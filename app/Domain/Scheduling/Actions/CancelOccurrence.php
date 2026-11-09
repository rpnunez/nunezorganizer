<?php

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Scheduling\Events\OccurrenceChanged;
use App\Enums\OccurrenceState;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel one scheduled block (the task itself stays open). Idempotent.
 * Finished blocks are history and are left untouched.
 */
class CancelOccurrence
{
    public function handle(TaskOccurrence $occurrence, User $actor): TaskOccurrence
    {
        $result = DB::transaction(function () use ($occurrence, $actor): array {
            // Same lock order as every other writer: task, then occurrence.
            Task::query()->whereKey($occurrence->task_id)->lockForUpdate()->firstOrFail();

            /** @var TaskOccurrence $locked */
            $locked = TaskOccurrence::query()->whereKey($occurrence->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state->isTerminal()) {
                return [$locked, false];
            }

            $locked->forceFill([
                'state' => OccurrenceState::Cancelled,
                'closed_at' => now(),
                'version' => $locked->version + 1,
            ])->save();

            Audit::record('occurrence.cancelled', $locked, ['task_id' => $locked->task_id], actor: $actor);

            return [$locked, true];
        });

        [$locked, $changed] = $result;

        if ($changed) {
            OccurrenceChanged::dispatch($locked->id, $locked->account_id, $locked->version, 'cancelled');
        }

        return $locked;
    }
}
