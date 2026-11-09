<?php

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Audit;
use App\Domain\Scheduling\LocalTime;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Create a new scheduled attempt for an open task.
 *
 * Inputs are ISO-8601 datetimes. With an explicit offset ("…-05:00" / "Z") they are
 * used as given; without one they are interpreted in the provided IANA timezone.
 * Stored as UTC. (#9 extends this with reschedule/cancel and DST policy.)
 */
class ScheduleOccurrence
{
    public const int MAX_DURATION_MINUTES = 24 * 60;

    public function handle(Task $task, string $startsAt, string $endsAt, string $timezone, ?User $actor = null): TaskOccurrence
    {
        if (! $task->isOpen()) {
            throw new TaskStateConflict('Only open tasks can be scheduled.');
        }

        $start = self::toUtc($startsAt, $timezone);
        $end = self::toUtc($endsAt, $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['occurrence.ends_at' => __('The end time must be after the start time.')]);
        }

        if ($start->diffInMinutes($end) > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages(['occurrence.ends_at' => __('A scheduled block cannot be longer than 24 hours.')]);
        }

        $occurrence = new TaskOccurrence([
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
            'timezone' => $timezone,
        ]);
        $occurrence->task_id = $task->id;
        $occurrence->save();

        Audit::record('occurrence.scheduled', $occurrence, [
            'task_id' => $task->id,
            'start' => $start->toIso8601String(),
            'end' => $end->toIso8601String(),
        ], actor: $actor);

        return $occurrence;
    }

    public static function toUtc(string $value, string $timezone): CarbonImmutable
    {
        $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value));

        // Offset-less local times follow the DST policy in LocalTime (ADR 0003).
        return $hasOffset ? CarbonImmutable::parse($value)->utc() : LocalTime::toUtc($value, $timezone);
    }
}
