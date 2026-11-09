<?php

namespace Tests\Feature\Web;

use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * #12 create / edit / schedule / respond / reschedule / cancel, all from the browser.
 */
class TaskPagesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00:00', 'UTC'));
        $this->withoutTenancy(fn () => UserPreference::where('user_id', $this->alice()->id)->update(['timezone' => 'America/New_York']));
        $this->actingAs($this->alice());
    }

    private function aliceTasks()
    {
        return $this->asTenant($this->alice(), fn () => Task::with('occurrences')->get());
    }

    public function test_create_task_with_a_block_in_the_users_timezone(): void
    {
        Livewire::test('pages::tasks.create')
            ->set('title', 'Organize my desk')
            ->set('duration', 15)
            ->set('date', '2026-11-10')
            ->set('start', '19:00')
            ->set('end', '19:15')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $task = $this->aliceTasks()->sole();
        $this->assertSame('Organize my desk', $task->title);
        $this->assertSame('2026-11-11T00:00:00Z', $task->occurrences->sole()->scheduled_start_at->toIso8601ZuluString());
        $this->assertSame('America/New_York', $task->occurrences->sole()->timezone);
    }

    public function test_create_without_scheduling(): void
    {
        Livewire::test('pages::tasks.create')->set('title', 'Someday')->set('schedule', false)->call('save')->assertHasNoErrors();

        $this->assertCount(0, $this->aliceTasks()->sole()->occurrences);
    }

    public function test_double_submit_creates_one_task(): void
    {
        Livewire::test('pages::tasks.create')
            ->set('title', 'Once only')
            ->call('save')
            ->call('save');

        $this->assertCount(1, $this->aliceTasks());
    }

    public function test_create_validates_input(): void
    {
        Livewire::test('pages::tasks.create')
            ->set('title', '')
            ->set('start', '19:00')
            ->set('end', '18:00')
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'end']);

        $this->assertCount(0, $this->aliceTasks());
    }

    public function test_end_follows_start_and_duration(): void
    {
        Livewire::test('pages::tasks.create')
            ->set('start', '07:30')
            ->set('duration', 45)
            ->assertSet('end', '08:15');
    }

    public function test_schedule_another_attempt_keeps_the_same_task(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create());

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('date', '2026-11-11')
            ->set('start', '08:00')
            ->set('end', '08:15')
            ->call('scheduleAttempt')
            ->assertHasNoErrors()
            ->assertSee('Tomorrow · 8:00 AM');

        $this->assertCount(1, $this->aliceTasks());
        $this->assertCount(1, $this->aliceTasks()->sole()->occurrences);
    }

    public function test_double_submit_schedules_only_one_attempt(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create());

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('date', '2026-11-11')
            ->set('start', '08:00')
            ->set('end', '08:15')
            ->call('scheduleAttempt')
            ->call('scheduleAttempt')
            ->assertHasNoErrors();

        $this->assertCount(1, $this->aliceTasks()->sole()->occurrences);
    }

    public function test_respond_move_and_cancel_blocks_from_the_task_page(): void
    {
        [$task, $past, $upcoming] = $this->asTenant($this->alice(), function () {
            $task = Task::factory()->create();

            return [
                $task,
                TaskOccurrence::factory()->forTask($task)->at('2026-11-10 16:00:00')->create(),
                TaskOccurrence::factory()->forTask($task)->at('2026-11-12 16:00:00')->create(),
            ];
        });

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('respond', $past->id, 'no')
            ->call('startMove', $upcoming->id)
            ->assertSet('moveStart', '11:00')
            ->set('moveStart', '12:00')
            ->set('moveEnd', '12:15')
            ->call('saveMove')
            ->assertHasNoErrors()
            ->call('cancelOccurrence', $upcoming->id);

        $blocks = $this->withoutTenancy(fn () => TaskOccurrence::whereIn('id', [$past->id, $upcoming->id])->get()->keyBy('id'));
        $this->assertSame(OccurrenceState::Missed, $blocks[$past->id]->state);
        $this->assertSame(OccurrenceState::Cancelled, $blocks[$upcoming->id]->state);
        $this->assertSame('2026-11-12T17:00:00Z', $blocks[$upcoming->id]->scheduled_start_at->toIso8601ZuluString());
        $this->assertSame(TaskStatus::Open, $this->aliceTasks()->sole()->status);
    }

    public function test_mark_done_then_correct_completion_time(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create());

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('completeTask', $task->id)
            ->assertSee('Finished at a different time?')
            ->set('doneDate', '2026-11-10')
            ->set('doneTime', '09:30')
            ->call('correctCompletion')
            ->assertHasNoErrors();

        $this->assertSame('2026-11-10T14:30:00Z', $this->aliceTasks()->sole()->completed_at?->toIso8601ZuluString());
    }

    public function test_cancel_task_with_confirmation(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create());

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->assertSee('Cancel this task?')
            ->call('cancelTask')
            ->assertDontSee('Mark done');

        $this->assertSame(TaskStatus::Cancelled, $this->aliceTasks()->sole()->status);
    }

    public function test_edit_task(): void
    {
        $task = $this->asTenant($this->alice(), fn () => Task::factory()->create(['title' => 'Old']));

        Livewire::test('pages::tasks.edit', ['task' => $task])
            ->assertSet('title', 'Old')
            ->set('title', 'New title')
            ->set('duration', 30)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame('New title', $this->aliceTasks()->sole()->title);
        $this->assertSame(30, $this->aliceTasks()->sole()->duration_minutes);
    }

    public function test_task_list_filters_by_status(): void
    {
        $this->asTenant($this->alice(), function () {
            Task::factory()->create(['title' => 'Open one']);
            Task::factory()->completed()->create(['title' => 'Done one']);
        });

        Livewire::test('pages::tasks.index')
            ->assertSee('Open one')->assertDontSee('Done one')
            ->set('status', 'completed')
            ->assertSee('Done one')->assertDontSee('Open one');
    }

    public function test_someone_elses_task_pages_are_not_found(): void
    {
        $bobTask = $this->asTenant($this->bob(), fn () => Task::factory()->create());

        $this->get(route('tasks.show', $bobTask))->assertNotFound();
        $this->get(route('tasks.edit', $bobTask))->assertNotFound();
    }

    public function test_tampered_component_state_cannot_reach_another_users_task(): void
    {
        $aliceTask = $this->asTenant($this->alice(), fn () => Task::factory()->create());
        $bobTask = $this->asTenant($this->bob(), fn () => Task::factory()->create());

        // The task ID is #[Locked]: the browser cannot change which task the page acts on.
        $this->expectException(CannotUpdateLockedPropertyException::class);

        try {
            Livewire::test('pages::tasks.show', ['task' => $aliceTask])
                ->set('taskId', $bobTask->id)
                ->call('cancelTask');
        } finally {
            $this->assertSame(TaskStatus::Open, $this->withoutTenancy(fn () => Task::find($bobTask->id))?->status);
            $this->assertSame(TaskStatus::Open, $this->withoutTenancy(fn () => Task::find($aliceTask->id))?->status);
        }
    }
}
