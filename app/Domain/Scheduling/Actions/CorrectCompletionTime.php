<?php

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Scheduling\PunctualityClassifier;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "I actually finished earlier/later": correct a completed task's completion time.
 *
 * Responses are append-only, so the correction is a new "yes" response (the latest
 * one is authoritative) plus an audit event with the old and new values.
 */
class CorrectCompletionTime
{
    public function handle(Task $task, CarbonInterface $completedAt, User $actor, ResponseChannel $channel = ResponseChannel::Web): CompletionResponse
    {
        if ($completedAt->greaterThan(now()->addSeconds(RecordResponse::FUTURE_SKEW_SECONDS))) {
            throw ValidationException::withMessages(['completed_at' => __('The completion time cannot be in the future.')]);
        }

        return DB::transaction(function () use ($task, $completedAt, $actor, $channel): CompletionResponse {
            /** @var Task $locked */
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TaskStatus::Completed || $locked->completed_at === null) {
                throw new TaskStateConflict('Only completed tasks can have their completion time corrected.');
            }

            $finished = TaskOccurrence::query()
                ->where('task_id', $locked->id)
                ->where('state', OccurrenceState::Finished)
                ->latest('closed_at')
                ->first();

            $grace = (int) (UserPreference::query()->where('user_id', $locked->owner_user_id)->value('grace_minutes')
                ?? UserPreference::DEFAULT_GRACE_MINUTES);

            $punctuality = $finished === null ? null : PunctualityClassifier::classify($completedAt, $finished->scheduled_end_at, $grace);
            $previous = $locked->completed_at->toIso8601ZuluString();

            $response = new CompletionResponse;
            $response->forceFill([
                'task_id' => $locked->id,
                'occurrence_id' => $finished?->id,
                'user_id' => $actor->id,
                'response' => ResponseType::Yes,
                'response_at' => now(),
                'completed_at' => $completedAt,
                'punctuality' => $punctuality,
                'channel' => $channel,
                'idempotency_key' => 'correction:'.Str::ulid(),
                'notes' => 'Completion time corrected.',
            ])->save();

            $locked->forceFill(['completed_at' => $completedAt])->save();

            Audit::record('task.completion_corrected', $locked, [
                'from' => $previous,
                'to' => $completedAt->toIso8601ZuluString(),
                'punctuality' => $punctuality?->value,
            ], actor: $actor);

            return $response;
        });
    }
}
