<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\Actions\RecordResponse;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\OccurrenceState;
use App\Enums\Punctuality;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\CompletionResponse;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * #9 domain rules: Yes completes once, No/silence never complete, idempotent retries,
 * punctuality from the planned end, fixed lock order.
 */
class RecordResponseTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 15:20:00', 'UTC'));
    }

    /**
     * Alice's task with a 15:00–15:15 UTC block.
     *
     * @return array{0: Task, 1: TaskOccurrence}
     */
    private function aliceBlock(OccurrenceState $state = OccurrenceState::AwaitingResponse): array
    {
        return $this->asTenant($this->alice(), function () use ($state) {
            $task = Task::factory()->create();
            $occurrence = TaskOccurrence::factory()->forTask($task)->at('2026-11-09 15:00:00')->state(['state' => $state])->create();

            return [$task, $occurrence];
        });
    }

    private function respond(Task $task, ?TaskOccurrence $occurrence, ResponseType $type, string $key, ?string $completedAt = null, ?User $actor = null)
    {
        $actor ??= $this->alice();

        return $this->asTenant($actor, fn () => app(RecordResponse::class)->handle(
            $task, $occurrence, $type, $actor, ResponseChannel::Telegram, $key,
            $completedAt ? CarbonImmutable::parse($completedAt, 'UTC') : null,
        ));
    }

    private function fresh(Task $task): Task
    {
        return $this->withoutTenancy(fn () => Task::findOrFail($task->id));
    }

    private function freshOccurrence(TaskOccurrence $occurrence): TaskOccurrence
    {
        return $this->withoutTenancy(fn () => TaskOccurrence::findOrFail($occurrence->id));
    }

    // ---- Yes ------------------------------------------------------------------------

    public function test_yes_completes_the_task_and_finishes_the_block(): void
    {
        [$task, $occurrence] = $this->aliceBlock();

        $outcome = $this->respond($task, $occurrence, ResponseType::Yes, 'tg:1');

        $this->assertFalse($outcome->replayed);
        $this->assertSame(TaskStatus::Completed, $this->fresh($task)->status);
        $this->assertSame(OccurrenceState::Finished, $this->freshOccurrence($occurrence)->state);
        $this->assertSame(Punctuality::WithinGrace, $outcome->response->punctuality, '15:20 is within 15 min grace of 15:15');
        $this->assertSame(1, AuditEvent::where('action', 'task.completed')->count());
    }

    public function test_same_key_twice_applies_once_and_replays(): void
    {
        [$task, $occurrence] = $this->aliceBlock();

        $first = $this->respond($task, $occurrence, ResponseType::Yes, 'tg:dup');
        $second = $this->respond($task, $occurrence, ResponseType::Yes, 'tg:dup');

        $this->assertTrue($second->replayed);
        $this->assertSame($first->response->id, $second->response->id);
        $this->assertSame(1, $this->withoutTenancy(fn () => CompletionResponse::count()));
        $this->assertSame(1, AuditEvent::where('action', 'task.completed')->count(), 'No duplicate audit transition (T08).');
    }

    public function test_second_yes_with_a_different_key_does_not_complete_twice(): void
    {
        [$task, $occurrence] = $this->aliceBlock();
        $this->respond($task, $occurrence, ResponseType::Yes, 'tg:a');

        $this->expectException(TaskStateConflict::class);
        $this->respond($task, $occurrence, ResponseType::Yes, 'web:b');
    }

    public function test_no_after_yes_leaves_the_task_completed(): void
    {
        [$task, $occurrence] = $this->aliceBlock();
        $this->respond($task, $occurrence, ResponseType::Yes, 'k1');

        try {
            $this->respond($task, $occurrence, ResponseType::No, 'k2');
            $this->fail('Expected conflict.');
        } catch (TaskStateConflict) {
        }

        $this->assertSame(TaskStatus::Completed, $this->fresh($task)->status);
        $this->assertSame(OccurrenceState::Finished, $this->freshOccurrence($occurrence)->state);
    }

    public function test_late_yes_on_a_missed_block_still_completes_and_is_classified_late(): void
    {
        [$task, $occurrence] = $this->aliceBlock(OccurrenceState::Missed);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));

        $outcome = $this->respond($task, $occurrence, ResponseType::Yes, 'late');

        $this->assertSame(TaskStatus::Completed, $this->fresh($task)->status);
        $this->assertSame(Punctuality::Late, $outcome->response->punctuality);
    }

    public function test_reported_completion_time_drives_punctuality_not_the_response_time(): void
    {
        [$task, $occurrence] = $this->aliceBlock();

        // Answered at 15:20, but actually finished at 15:10 -> on time.
        $outcome = $this->respond($task, $occurrence, ResponseType::Yes, 'k', completedAt: '2026-11-09 15:10:00');

        $this->assertSame(Punctuality::OnTime, $outcome->response->punctuality);
        $this->assertSame('2026-11-09T15:10:00Z', $this->fresh($task)->completed_at?->toIso8601ZuluString());
    }

    public function test_grace_comes_from_the_owners_preferences(): void
    {
        $this->withoutTenancy(fn () => UserPreference::where('user_id', $this->alice()->id)->update(['grace_minutes' => 0]));
        [$task, $occurrence] = $this->aliceBlock();

        $this->assertSame(Punctuality::Late, $this->respond($task, $occurrence, ResponseType::Yes, 'k')->response->punctuality);
    }

    public function test_completing_cancels_other_upcoming_blocks_but_keeps_history(): void
    {
        [$task, $occurrence] = $this->aliceBlock();
        [$missed, $upcoming] = $this->asTenant($this->alice(), fn () => [
            TaskOccurrence::factory()->forTask($task)->at('2026-11-08 15:00:00')->missed()->create(),
            TaskOccurrence::factory()->forTask($task)->at('2026-11-10 15:00:00')->create(),
        ]);

        $this->respond($task, $occurrence, ResponseType::Yes, 'k');

        $this->assertSame(OccurrenceState::Missed, $this->freshOccurrence($missed)->state);
        $this->assertSame(OccurrenceState::Cancelled, $this->freshOccurrence($upcoming)->state);
        $this->assertSame(2, $this->freshOccurrence($upcoming)->version);
    }

    // ---- No / silence / partial (T06, T07) ----------------------------------------------

    public function test_no_marks_the_block_missed_and_keeps_the_task_open(): void
    {
        [$task, $occurrence] = $this->aliceBlock();

        $this->respond($task, $occurrence, ResponseType::No, 'k');

        $this->assertSame(TaskStatus::Open, $this->fresh($task)->status);
        $this->assertNull($this->fresh($task)->completed_at);
        $this->assertSame(OccurrenceState::Missed, $this->freshOccurrence($occurrence)->state);
    }

    public function test_silence_changes_nothing(): void
    {
        [$task, $occurrence] = $this->aliceBlock();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 00:00:00', 'UTC'));

        $this->assertSame(TaskStatus::Open, $this->fresh($task)->status);
        $this->assertSame(OccurrenceState::AwaitingResponse, $this->freshOccurrence($occurrence)->state);
    }

    public function test_partial_records_progress_and_keeps_everything_open(): void
    {
        [$task, $occurrence] = $this->aliceBlock();

        $this->respond($task, $occurrence, ResponseType::Partial, 'k');

        $this->assertSame(TaskStatus::Open, $this->fresh($task)->status);
        $this->assertSame(OccurrenceState::AwaitingResponse, $this->freshOccurrence($occurrence)->state);
        $this->assertSame(1, $this->withoutTenancy(fn () => CompletionResponse::count()));
    }

    // ---- guards ------------------------------------------------------------------------

    public function test_no_on_a_cancelled_task_is_rejected(): void
    {
        [$task, $occurrence] = $this->aliceBlock();
        $this->withoutTenancy(fn () => Task::whereKey($task->id)->update(['status' => TaskStatus::Cancelled]));

        $this->expectException(TaskStateConflict::class);
        $this->respond($task, $occurrence, ResponseType::No, 'k');
    }

    public function test_occurrence_must_belong_to_the_task(): void
    {
        [$task] = $this->aliceBlock();
        [, $otherOccurrence] = $this->aliceBlock();

        $this->expectException(ValidationException::class);
        $this->respond($task, $otherOccurrence, ResponseType::Yes, 'k');
    }

    public function test_future_completion_time_and_snooze_are_rejected(): void
    {
        [$task, $occurrence] = $this->aliceBlock();

        foreach ([
            fn () => $this->respond($task, $occurrence, ResponseType::Yes, 'f', completedAt: '2026-11-09 18:00:00'),
            fn () => $this->respond($task, $occurrence, ResponseType::Snooze, 's'),
            fn () => $this->respond($task, null, ResponseType::No, 'n'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected validation error.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(TaskStatus::Open, $this->fresh($task)->status);
    }

    public function test_idempotency_keys_do_not_cross_tenants(): void
    {
        [$aliceTask, $aliceBlock] = $this->aliceBlock();
        [$bobTask, $bobBlock] = $this->asTenant($this->bob(), function () {
            $task = Task::factory()->create();

            return [$task, TaskOccurrence::factory()->forTask($task)->at('2026-11-09 15:00:00')->awaitingResponse()->create()];
        });

        $this->respond($aliceTask, $aliceBlock, ResponseType::Yes, 'same-key');
        $bobOutcome = $this->respond($bobTask, $bobBlock, ResponseType::No, 'same-key', actor: $this->bob());

        $this->assertFalse($bobOutcome->replayed, 'Bob must not receive Alice\'s replayed response.');
        $this->assertSame(TaskStatus::Open, $this->fresh($bobTask)->status);
    }

    public function test_rows_are_locked_task_first_then_occurrence(): void
    {
        [$task, $occurrence] = $this->aliceBlock();
        $locks = [];

        DB::listen(function ($query) use (&$locks) {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = str_contains($query->sql, 'task_occurrences') ? 'occurrence' : 'task';
            }
        });

        $this->respond($task, $occurrence, ResponseType::Yes, 'k');

        $this->assertSame(['task', 'occurrence'], array_slice($locks, 0, 2));
    }
}
