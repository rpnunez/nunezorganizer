<?php

namespace Database\Factories;

use App\Enums\OccurrenceState;
use App\Models\Task;
use App\Models\TaskOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskOccurrence>
 */
class TaskOccurrenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addHour()->startOfMinute();

        return [
            'task_id' => Task::factory(),
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->addMinutes(15),
            'timezone' => 'UTC',
            'state' => OccurrenceState::Scheduled,
            'version' => 1,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TaskOccurrence $occurrence): void {
            if ($occurrence->getAttribute('account_id') === null) {
                $occurrence->account_id = (string) Task::withoutGlobalScopes()->whereKey($occurrence->task_id)->value('account_id');
            }
        });
    }

    public function forTask(Task $task): static
    {
        return $this->state(['task_id' => $task->id]);
    }

    public function at(string $startUtc, int $minutes = 15): static
    {
        return $this->state(fn () => [
            'scheduled_start_at' => $start = now()->parse($startUtc),
            'scheduled_end_at' => $start->addMinutes($minutes),
        ]);
    }

    public function missed(): static
    {
        return $this->state(['state' => OccurrenceState::Missed, 'closed_at' => now()]);
    }

    public function awaitingResponse(): static
    {
        return $this->state(['state' => OccurrenceState::AwaitingResponse]);
    }
}
