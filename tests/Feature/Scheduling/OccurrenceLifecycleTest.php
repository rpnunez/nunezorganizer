<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\Actions\CancelOccurrence;
use App\Domain\Scheduling\Actions\CorrectCompletionTime;
use App\Domain\Scheduling\Actions\RecordResponse;
use App\Domain\Scheduling\Actions\RescheduleOccurrence;
use App\Domain\Scheduling\Events\OccurrenceChanged;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\OccurrenceState;
use App\Enums\Punctuality;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Models\AuditEvent;
use App\Models\CompletionResponse;
use App\Models\Task;
use App\Models\TaskOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OccurrenceLifecycleTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    }

    private function upcoming(): TaskOccurrence
    {
        return $this->asTenant($this->alice(), fn () => TaskOccurrence::factory()->at('2026-11-09 15:00:00')->create());
    }

    public function test_reschedule_moves_the_block_bumps_version_and_announces_it(): void
    {
        Event::fake([OccurrenceChanged::class]);
        $occurrence = $this->upcoming();

        $moved = $this->asTenant($this->alice(), fn () => app(RescheduleOccurrence::class)
            ->handle($occurrence, '2026-11-10 19:00', '2026-11-10 19:15', 'America/New_York', $this->alice()));

        $this->assertSame('2026-11-11T00:00:00Z', $moved->scheduled_start_at->toIso8601ZuluString());
        $this->assertSame(2, $moved->version);
        $this->assertSame($occurrence->task_id, $moved->task_id, 'Same logical task, no duplicate.');
        Event::assertDispatched(OccurrenceChanged::class, fn ($e) => $e->occurrenceId === $occurrence->id && $e->version === 2 && $e->change === 'rescheduled');
        $this->assertTrue(AuditEvent::where('action', 'occurrence.rescheduled')->exists());
    }

    public function test_ended_blocks_cannot_be_moved(): void
    {
        $occurrence = $this->asTenant($this->alice(), fn () => TaskOccurrence::factory()->at('2026-11-09 09:00:00')->missed()->create());

        $this->expectException(TaskStateConflict::class);
        $this->asTenant($this->alice(), fn () => app(RescheduleOccurrence::class)
            ->handle($occurrence, '2026-11-10 10:00', '2026-11-10 10:15', 'UTC', $this->alice()));
    }

    public function test_reschedule_into_a_dst_gap_follows_the_policy(): void
    {
        $occurrence = $this->upcoming();

        $moved = $this->asTenant($this->alice(), fn () => app(RescheduleOccurrence::class)
            ->handle($occurrence, '2027-03-14 02:30', '2027-03-14 03:30', 'America/New_York', $this->alice()));

        // 02:30 does not exist that day -> 03:00 EDT (07:00Z); 03:30 EDT = 07:30Z.
        $this->assertSame('2027-03-14T07:00:00Z', $moved->scheduled_start_at->toIso8601ZuluString());
        $this->assertSame('2027-03-14T07:30:00Z', $moved->scheduled_end_at->toIso8601ZuluString());
    }

    public function test_cancel_is_idempotent_and_keeps_the_task_open(): void
    {
        Event::fake([OccurrenceChanged::class]);
        $occurrence = $this->upcoming();
        $cancel = fn () => $this->asTenant($this->alice(), fn () => app(CancelOccurrence::class)->handle($occurrence, $this->alice()));

        $first = $cancel();
        $second = $cancel();

        $this->assertSame(OccurrenceState::Cancelled, $second->state);
        $this->assertSame($first->version, $second->version);
        Event::assertDispatchedTimes(OccurrenceChanged::class, 1);
        $this->assertTrue($this->asTenant($this->alice(), fn () => Task::findOrFail($occurrence->task_id))->isOpen());
    }

    public function test_completion_time_correction_is_appended_and_audited(): void
    {
        $occurrence = $this->upcoming();
        $task = $this->asTenant($this->alice(), fn () => Task::findOrFail($occurrence->task_id));
        $this->travelTo(CarbonImmutable::parse('2026-11-09 18:00:00', 'UTC'));

        $this->asTenant($this->alice(), fn () => app(RecordResponse::class)
            ->handle($task, $occurrence, ResponseType::Yes, $this->alice(), ResponseChannel::Web, 'web:1'));

        $correction = $this->asTenant($this->alice(), fn () => app(CorrectCompletionTime::class)
            ->handle($task, CarbonImmutable::parse('2026-11-09 15:10:00', 'UTC'), $this->alice()));

        $this->assertSame(Punctuality::OnTime, $correction->punctuality);
        $this->assertSame('2026-11-09T15:10:00Z', $this->asTenant($this->alice(), fn () => $task->fresh())?->completed_at?->toIso8601ZuluString());
        $this->assertSame(2, $this->asTenant($this->alice(), fn () => CompletionResponse::count()), 'Original response kept (append-only).');
        $this->assertTrue(AuditEvent::where('action', 'task.completion_corrected')->exists());
    }

    public function test_open_tasks_cannot_have_a_completion_time_corrected(): void
    {
        $occurrence = $this->upcoming();
        $task = $this->asTenant($this->alice(), fn () => Task::findOrFail($occurrence->task_id));

        $this->expectException(TaskStateConflict::class);
        $this->asTenant($this->alice(), fn () => app(CorrectCompletionTime::class)->handle($task, now(), $this->alice()));
    }
}
