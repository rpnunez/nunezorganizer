<?php

namespace Tests\Feature\Web;

use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * #11 Today and Backlog pages.
 */
class TodayAndBacklogPagesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00:00', 'UTC')); // 13:00 in New York
    }

    private function block(User $user, string $title, string $startUtc, OccurrenceState $state = OccurrenceState::Scheduled): TaskOccurrence
    {
        return $this->asTenant($user, function () use ($title, $startUtc, $state) {
            $task = Task::factory()->create(['title' => $title]);

            return TaskOccurrence::factory()->forTask($task)->at($startUtc)->state(['state' => $state])->create();
        });
    }

    public function test_today_shows_pending_answers_todays_blocks_and_backlog_count_in_local_time(): void
    {
        $alice = $this->alice();
        $this->withoutTenancy(fn () => UserPreference::where('user_id', $alice->id)->update(['timezone' => 'America/New_York']));

        $this->block($alice, 'Ended, never answered', '2026-11-10 16:00:00');         // 11:00 NY, ended
        $this->block($alice, 'Evening walk', '2026-11-11 00:00:00');                   // 19:00 NY today
        $this->block($alice, 'Missed yesterday', '2026-11-09 15:00:00', OccurrenceState::Missed);
        $this->block($this->bob(), 'Bob secret', '2026-11-10 16:00:00');

        $this->actingAs($alice)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Did you finish?')
            ->assertSee('Ended, never answered')
            ->assertSee('Evening walk')
            ->assertSee('7:00 PM')                     // rendered in the user's timezone
            ->assertSee('1 unfinished task carried over')
            ->assertDontSee('Bob secret');
    }

    public function test_empty_states(): void
    {
        $this->actingAs($this->alice())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing scheduled today.')
            ->assertSee('Backlog is clear');

        $this->actingAs($this->alice())->get(route('backlog'))->assertOk()->assertSee('All caught up');
    }

    public function test_yes_from_today_completes_the_task(): void
    {
        $alice = $this->alice();
        $block = $this->block($alice, 'Organize my desk', '2026-11-10 16:00:00');
        $this->actingAs($alice);

        Livewire::test('pages::today')
            ->assertSee('Organize my desk')
            ->call('respond', $block->id, 'yes')
            ->assertHasNoErrors()
            ->assertDontSee('Did you finish?');

        $this->assertSame(TaskStatus::Completed, $this->withoutTenancy(fn () => Task::find($block->task_id))?->status);
    }

    public function test_no_from_today_keeps_the_task_open(): void
    {
        $alice = $this->alice();
        $block = $this->block($alice, 'Organize my desk', '2026-11-10 16:00:00');
        $this->actingAs($alice);

        Livewire::test('pages::today')->call('respond', $block->id, 'no');

        $fresh = $this->withoutTenancy(fn () => TaskOccurrence::find($block->id));
        $this->assertSame(OccurrenceState::Missed, $fresh?->state);
        $this->assertSame(TaskStatus::Open, $this->withoutTenancy(fn () => Task::find($block->task_id))?->status);
    }

    public function test_double_tap_records_a_single_answer(): void
    {
        $alice = $this->alice();
        $block = $this->block($alice, 'Organize my desk', '2026-11-10 16:00:00');
        $this->actingAs($alice);

        Livewire::test('pages::today')
            ->call('respond', $block->id, 'no')
            ->call('respond', $block->id, 'no');

        $this->assertSame(1, $this->withoutTenancy(fn () => Task::find($block->task_id)?->responses()->count()));
    }

    public function test_backlog_lists_each_carried_task_once_and_can_complete_it(): void
    {
        $alice = $this->alice();
        $first = $this->block($alice, 'Organize my desk', '2026-11-08 15:00:00', OccurrenceState::Missed);
        $this->asTenant($alice, fn () => TaskOccurrence::factory()->forTask(Task::find($first->task_id))->at('2026-11-09 15:00:00')->state(['state' => OccurrenceState::Unanswered])->create());
        $this->actingAs($alice);

        Livewire::test('pages::backlog')
            ->assertSee('Organize my desk')
            ->assertSee('2 attempts')
            ->call('completeTask', $first->task_id)
            ->assertSee('All caught up');
    }

    public function test_users_cannot_act_on_someone_elses_blocks(): void
    {
        $bobBlock = $this->block($this->bob(), 'Bob task', '2026-11-10 16:00:00');
        $this->actingAs($this->alice());

        // Bob's block ID is simply not found inside Alice's tenant.
        Livewire::test('pages::today')->call('respond', $bobBlock->id, 'yes')->assertStatus(404);

        $this->assertSame(TaskStatus::Open, $this->withoutTenancy(fn () => Task::find($bobBlock->task_id))?->status);
    }

    public function test_pages_require_login(): void
    {
        foreach (['dashboard', 'backlog', 'tasks.index', 'tasks.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_pages_have_accessible_structure(): void
    {
        $this->block($this->alice(), 'Organize my desk', '2026-11-10 16:00:00');

        $this->actingAs($this->alice())->get(route('dashboard'))
            ->assertSee('<h1', false)
            ->assertSee('aria-labelledby="pending-heading"', false)
            ->assertSee('aria-label="Main"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('aria-label="Yes, I finished Organize my desk"', false);
    }
}
