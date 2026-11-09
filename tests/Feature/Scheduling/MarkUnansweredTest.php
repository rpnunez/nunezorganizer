<?php

namespace Tests\Feature\Scheduling;

use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * T07: silence ages into `unanswered` after the OWNER's configurable timeout
 * (default 12h). The task stays open and in the backlog.
 */
class MarkUnansweredTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function block(User $user, string $startUtc, OccurrenceState $state = OccurrenceState::AwaitingResponse, array $taskState = []): TaskOccurrence
    {
        return $this->asTenant($user, function () use ($startUtc, $state, $taskState) {
            $task = Task::factory()->state($taskState)->create();

            return TaskOccurrence::factory()->forTask($task)->at($startUtc)->state(['state' => $state])->create();
        });
    }

    private function stateOf(TaskOccurrence $occurrence): OccurrenceState
    {
        return $this->withoutTenancy(fn () => TaskOccurrence::findOrFail($occurrence->id))->state;
    }

    public function test_default_twelve_hour_timeout(): void
    {
        $block = $this->block($this->alice(), '2026-11-09 15:00:00'); // ends 15:15Z

        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:14:59', 'UTC'));
        $this->artisan('nunez:mark-unanswered')->assertSuccessful();
        $this->assertSame(OccurrenceState::AwaitingResponse, $this->stateOf($block), 'Not before end + 12h.');

        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:15:00', 'UTC'));
        $this->artisan('nunez:mark-unanswered')->expectsOutputToContain('Marked 1')->assertSuccessful();
        $this->assertSame(OccurrenceState::Unanswered, $this->stateOf($block));
    }

    public function test_each_user_timeout_is_respected(): void
    {
        $this->withoutTenancy(fn () => UserPreference::where('user_id', $this->bob()->id)->update(['unanswered_after_minutes' => 60]));
        $aliceBlock = $this->block($this->alice(), '2026-11-09 15:00:00');
        $bobBlock = $this->block($this->bob(), '2026-11-09 15:00:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-09 16:30:00', 'UTC'));
        $this->artisan('nunez:mark-unanswered')->assertSuccessful();

        $this->assertSame(OccurrenceState::AwaitingResponse, $this->stateOf($aliceBlock), 'Alice: 12h default.');
        $this->assertSame(OccurrenceState::Unanswered, $this->stateOf($bobBlock), 'Bob: 60 minutes.');
    }

    public function test_never_prompted_blocks_age_too(): void
    {
        $block = $this->block($this->alice(), '2026-11-09 15:00:00', OccurrenceState::Scheduled);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

        $this->artisan('nunez:mark-unanswered')->assertSuccessful();

        $this->assertSame(OccurrenceState::Unanswered, $this->stateOf($block));
    }

    public function test_answered_closed_and_non_open_task_blocks_are_untouched(): void
    {
        $alice = $this->alice();
        $missed = $this->block($alice, '2026-11-09 15:00:00', OccurrenceState::Missed);
        $finished = $this->block($alice, '2026-11-09 15:00:00', OccurrenceState::Finished);
        $ofCompletedTask = $this->block($alice, '2026-11-09 15:00:00', OccurrenceState::AwaitingResponse, ['status' => TaskStatus::Completed, 'completed_at' => now()]);
        $future = $this->block($alice, '2026-11-12 15:00:00', OccurrenceState::Scheduled);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:00:00', 'UTC'));

        $this->artisan('nunez:mark-unanswered')->expectsOutputToContain('Marked 0')->assertSuccessful();

        $this->assertSame(OccurrenceState::Missed, $this->stateOf($missed));
        $this->assertSame(OccurrenceState::Finished, $this->stateOf($finished));
        $this->assertSame(OccurrenceState::AwaitingResponse, $this->stateOf($ofCompletedTask));
        $this->assertSame(OccurrenceState::Scheduled, $this->stateOf($future));
    }

    public function test_unanswered_is_not_no_task_stays_open_in_backlog_and_can_still_be_completed(): void
    {
        $block = $this->block($this->alice(), '2026-11-09 15:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
        $this->artisan('nunez:mark-unanswered')->assertSuccessful();

        $task = $this->withoutTenancy(fn () => Task::findOrFail($block->task_id));
        $this->assertSame(TaskStatus::Open, $task->status);

        Sanctum::actingAs($this->alice(), ['tasks:read', 'tasks:write']);
        $this->getJson('/api/v1/backlog?date=2026-11-10')->assertJsonPath('data.0.attempts.0.state', 'unanswered');
        $this->postJson("/api/v1/occurrences/{$block->id}/responses", ['response' => 'yes'])->assertCreated()
            ->assertJsonPath('task.status', 'completed');
    }

    public function test_rerun_is_idempotent_and_audited_once(): void
    {
        $this->block($this->alice(), '2026-11-09 15:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

        $this->artisan('nunez:mark-unanswered')->assertSuccessful();
        $this->artisan('nunez:mark-unanswered')->expectsOutputToContain('Marked 0')->assertSuccessful();

        $this->assertSame(1, AuditEvent::where('action', 'occurrence.unanswered')->count());
    }

    public function test_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'nunez:mark-unanswered'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
