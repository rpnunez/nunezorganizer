<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Explicit, audited cancellation. Idempotent: cancelling a cancelled task is a no-op.
 * A completed task cannot be cancelled. Pending occurrences are cancelled too.
 */
class CancelTask
{
    public function handle(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            /** @var Task $locked */
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === TaskStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status === TaskStatus::Completed) {
                throw new TaskStateConflict('A completed task cannot be cancelled.');
            }

            $locked->forceFill([
                'status' => TaskStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $locked->occurrences()
                ->whereIn('state', [OccurrenceState::Scheduled, OccurrenceState::AwaitingResponse])
                ->update(['state' => OccurrenceState::Cancelled, 'closed_at' => now(), 'version' => DB::raw('version + 1')]);

            Audit::record('task.cancelled', $locked, actor: $actor);

            return $locked;
        });
    }
}
