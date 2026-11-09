<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edit an open task's descriptive fields. Status changes go through dedicated actions.
 */
class UpdateTask
{
    /**
     * @param  array{title?: string, description?: string|null, duration_minutes?: int}  $attributes
     */
    public function handle(Task $task, array $attributes, User $actor): Task
    {
        return DB::transaction(function () use ($task, $attributes, $actor): Task {
            /** @var Task $locked */
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new TaskStateConflict('Only open tasks can be edited.');
            }

            $locked->fill($attributes);
            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($changed !== []) {
                Audit::record('task.updated', $locked, ['fields' => $changed], actor: $actor);
            }

            return $locked;
        });
    }
}
