<?php

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Scheduling\Events\OccurrenceChanged;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\OccurrenceState;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move a not-yet-ended scheduled block. Bumps `version` so queued notifications for
 * the old time are recognized as stale. Blocks that already ended (awaiting a
 * response, missed, unanswered) are history: schedule a new attempt instead.
 */
class RescheduleOccurrence
{
    public function handle(TaskOccurrence $occurrence, string $startsAt, string $endsAt, string $timezone, User $actor): TaskOccurrence
    {
        $start = ScheduleOccurrence::toUtc($startsAt, $timezone);
        $end = ScheduleOccurrence::toUtc($endsAt, $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => __('The end time must be after the start time.')]);
        }

        if ($start->diffInMinutes($end) > ScheduleOccurrence::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages(['ends_at' => __('A scheduled block cannot be longer than 24 hours.')]);
        }

        $changed = DB::transaction(function () use ($occurrence, $start, $end, $timezone, $actor): TaskOccurrence {
            /** @var Task $task */
            $task = Task::query()->whereKey($occurrence->task_id)->lockForUpdate()->firstOrFail();
            /** @var TaskOccurrence $locked */
            $locked = TaskOccurrence::query()->whereKey($occurrence->getKey())->lockForUpdate()->firstOrFail();

            if (! $task->isOpen()) {
                throw new TaskStateConflict('Only blocks of open tasks can be rescheduled.');
            }

            if ($locked->state !== OccurrenceState::Scheduled) {
                throw new TaskStateConflict('Only upcoming blocks can be moved. Schedule a new attempt instead.');
            }

            $from = [$locked->scheduled_start_at->toIso8601ZuluString(), $locked->scheduled_end_at->toIso8601ZuluString()];

            $locked->forceFill([
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'timezone' => $timezone,
                'version' => $locked->version + 1,
            ])->save();

            Audit::record('occurrence.rescheduled', $locked, [
                'task_id' => $task->id,
                'from' => $from,
                'to' => [$start->toIso8601ZuluString(), $end->toIso8601ZuluString()],
                'version' => $locked->version,
            ], actor: $actor);

            return $locked;
        });

        OccurrenceChanged::dispatch($changed->id, $changed->account_id, $changed->version, 'rescheduled');

        return $changed;
    }
}
