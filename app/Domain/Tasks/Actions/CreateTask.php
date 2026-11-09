<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Scheduling\Actions\ScheduleOccurrence;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a task owned by the acting user in the current tenant, optionally with
 * an initial scheduled occurrence, atomically.
 */
class CreateTask
{
    public function __construct(private readonly ScheduleOccurrence $scheduleOccurrence) {}

    /**
     * @param  array{title: string, description?: string|null, duration_minutes?: int|null}  $attributes
     * @param  array{starts_at: string, ends_at: string, timezone: string}|null  $occurrence
     * @return array{task: Task, occurrence: TaskOccurrence|null}
     */
    public function handle(User $owner, array $attributes, ?array $occurrence = null): array
    {
        return DB::transaction(function () use ($owner, $attributes, $occurrence): array {
            $task = new Task([
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'duration_minutes' => $attributes['duration_minutes'] ?? 15,
            ]);
            $task->owner_user_id = $owner->id;
            $task->save();

            Audit::record('task.created', $task, actor: $owner);

            $scheduled = $occurrence === null
                ? null
                : $this->scheduleOccurrence->handle($task, $occurrence['starts_at'], $occurrence['ends_at'], $occurrence['timezone'], $owner);

            return ['task' => $task, 'occurrence' => $scheduled];
        });
    }
}
