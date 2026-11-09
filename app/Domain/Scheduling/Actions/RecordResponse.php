<?php

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Scheduling\PunctualityClassifier;
use App\Domain\Scheduling\ResponseOutcome;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\OccurrenceState;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Enums\TaskStatus;
use App\Models\CompletionResponse;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The single entry point for recording what a user reported (web, API, Telegram).
 *
 * Guarantees:
 *  - Same idempotency key twice -> the original response is returned; nothing changes.
 *  - Rows are locked task-then-occurrence inside one transaction (fixed order, no deadlocks),
 *    so concurrent Yes/No/web/bot submissions serialize.
 *  - Yes completes an open task exactly once; No and silence never complete anything.
 *  - Punctuality is measured against the occurrence's planned end plus the owner's grace.
 */
class RecordResponse
{
    /** Allowed clock skew when a client reports a completion time. */
    public const int FUTURE_SKEW_SECONDS = 120;

    public function handle(
        Task $task,
        ?TaskOccurrence $occurrence,
        ResponseType $type,
        User $actor,
        ResponseChannel $channel,
        string $idempotencyKey,
        ?CarbonInterface $completedAt = null,
        ?string $notes = null,
    ): ResponseOutcome {
        $this->validate($task, $occurrence, $type, $completedAt);

        try {
            return DB::transaction(fn () => $this->record($task, $occurrence, $type, $actor, $channel, $idempotencyKey, $completedAt, $notes));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won the race; return its result.
            return $this->replay($idempotencyKey) ?? throw new TaskStateConflict('Duplicate response.');
        }
    }

    private function record(
        Task $task,
        ?TaskOccurrence $occurrence,
        ResponseType $type,
        User $actor,
        ResponseChannel $channel,
        string $key,
        ?CarbonInterface $completedAt,
        ?string $notes,
    ): ResponseOutcome {
        /** @var Task $task */
        $task = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
        $occurrence = $occurrence === null
            ? null
            : TaskOccurrence::query()->whereKey($occurrence->getKey())->lockForUpdate()->firstOrFail();

        // Checked after locking: any concurrent request for this task is now serialized behind us.
        if ($replay = $this->replay($key)) {
            return $replay;
        }

        if ($task->status === TaskStatus::Cancelled) {
            throw new TaskStateConflict('This task was cancelled.');
        }

        if ($task->status === TaskStatus::Completed) {
            throw new TaskStateConflict('This task is already completed.');
        }

        if ($occurrence !== null && $occurrence->state->isTerminal()) {
            throw new TaskStateConflict('This scheduled block is already closed.');
        }

        $now = now();
        $response = new CompletionResponse;
        $response->forceFill([
            'task_id' => $task->id,
            'occurrence_id' => $occurrence?->id,
            'user_id' => $actor->id,
            'response' => $type,
            'response_at' => $now,
            'channel' => $channel,
            'idempotency_key' => $key,
            'notes' => $notes,
        ]);

        match ($type) {
            ResponseType::Yes => $this->applyYes($task, $occurrence, $response, $completedAt ?? $now, $actor),
            ResponseType::No => $this->applyNo($task, $occurrence ?? throw new LogicException('Validated above.'), $response, $actor),
            ResponseType::Partial => $this->applyPartial($task, $occurrence, $response, $actor),
            ResponseType::Snooze => null, // rejected in validate(); staged for a later release
        };

        return new ResponseOutcome($task, $occurrence, $response, replayed: false);
    }

    private function applyYes(Task $task, ?TaskOccurrence $occurrence, CompletionResponse $response, CarbonInterface $completedAt, User $actor): void
    {
        $punctuality = $occurrence === null
            ? null
            : PunctualityClassifier::classify($completedAt, $occurrence->scheduled_end_at, $this->graceMinutes($task));

        $response->forceFill(['completed_at' => $completedAt, 'punctuality' => $punctuality])->save();

        $task->forceFill(['status' => TaskStatus::Completed, 'completed_at' => $completedAt])->save();

        $occurrence?->forceFill(['state' => OccurrenceState::Finished, 'closed_at' => now()])->save();

        // The task is done: future attempts are no longer needed.
        $cancelled = TaskOccurrence::query()
            ->where('task_id', $task->id)
            ->when($occurrence !== null, fn ($q) => $q->whereKeyNot($occurrence?->id))
            ->where('state', OccurrenceState::Scheduled)
            ->update(['state' => OccurrenceState::Cancelled, 'closed_at' => now(), 'version' => DB::raw('version + 1')]);

        Audit::record('task.completed', $task, array_filter([
            'occurrence_id' => $occurrence?->id,
            'punctuality' => $punctuality?->value,
            'channel' => $response->channel->value,
            'future_occurrences_cancelled' => $cancelled ?: null,
        ]), actor: $actor);
    }

    private function applyNo(Task $task, TaskOccurrence $occurrence, CompletionResponse $response, User $actor): void
    {
        $response->save();

        // "Not finished by the check-in": the attempt is missed, the task stays open (and in the backlog).
        $occurrence->forceFill(['state' => OccurrenceState::Missed, 'closed_at' => now()])->save();

        Audit::record('occurrence.missed', $occurrence, [
            'task_id' => $task->id,
            'channel' => $response->channel->value,
        ], actor: $actor);
    }

    private function applyPartial(Task $task, ?TaskOccurrence $occurrence, CompletionResponse $response, User $actor): void
    {
        $response->save();

        Audit::record('task.partial_progress', $task, [
            'occurrence_id' => $occurrence?->id,
            'channel' => $response->channel->value,
        ], actor: $actor);
    }

    private function replay(string $key): ?ResponseOutcome
    {
        $existing = CompletionResponse::query()->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        $task = Task::withTrashed()->findOrFail($existing->task_id);
        $occurrence = $existing->occurrence_id ? TaskOccurrence::find($existing->occurrence_id) : null;

        return new ResponseOutcome($task, $occurrence, $existing, replayed: true);
    }

    private function validate(Task $task, ?TaskOccurrence $occurrence, ResponseType $type, ?CarbonInterface $completedAt): void
    {
        if ($type === ResponseType::Snooze) {
            throw ValidationException::withMessages(['response' => __('Snooze is not available yet.')]);
        }

        if ($type === ResponseType::No && $occurrence === null) {
            throw ValidationException::withMessages(['response' => __('A "no" response must refer to a scheduled block.')]);
        }

        if ($occurrence !== null && $occurrence->task_id !== $task->id) {
            throw ValidationException::withMessages(['occurrence' => __('The scheduled block does not belong to this task.')]);
        }

        if ($completedAt !== null && $type !== ResponseType::Yes) {
            throw ValidationException::withMessages(['completed_at' => __('A completion time can only accompany a "yes" response.')]);
        }

        if ($completedAt !== null && $completedAt->greaterThan(now()->addSeconds(self::FUTURE_SKEW_SECONDS))) {
            throw ValidationException::withMessages(['completed_at' => __('The completion time cannot be in the future.')]);
        }
    }

    private function graceMinutes(Task $task): int
    {
        return (int) (UserPreference::query()->where('user_id', $task->owner_user_id)->value('grace_minutes')
            ?? UserPreference::DEFAULT_GRACE_MINUTES);
    }
}
