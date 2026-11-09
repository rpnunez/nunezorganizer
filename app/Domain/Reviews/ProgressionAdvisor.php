<?php

namespace App\Domain\Reviews;

use Carbon\CarbonImmutable;

/** Pure week-end recommendation; never changes a plan or schedules a block. */
class ProgressionAdvisor
{
    /**
     * @param  array<string, array{planned: int, completed: int}>  $days
     * @return array{can_advance: bool, reason: string, completed_days: int, target_days: int, next_week: int|null}
     */
    public function advise(int $currentWeek, string $startedOn, string $todayLocal, array $days,
        int $backlog, int $targetDays, int $maxBacklog): array
    {
        $completedDays = count(array_filter($days, fn (array $day) => $day['completed'] > 0));
        $nextWeek = $currentWeek < 4 ? $currentWeek + 1 : null;
        $weekEnd = CarbonImmutable::parse($startedOn, 'UTC')->addDays($currentWeek * 7 - 1)->format('Y-m-d');
        $reason = match (true) {
            $nextWeek === null => 'You have reached the final week of this template.',
            $todayLocal < $weekEnd => 'Finish this week before reviewing your next step.',
            $completedDays < $targetDays => 'Keep this pace for another week. Your completed-day goal is not met yet.',
            $backlog > $maxBacklog => 'Keep this pace while you work through your backlog.',
            default => 'You met your goal. You can choose to advance next week.',
        };

        return ['can_advance' => $nextWeek !== null && $todayLocal >= $weekEnd
            && $completedDays >= $targetDays && $backlog <= $maxBacklog,
            'reason' => $reason, 'completed_days' => $completedDays,
            'target_days' => $targetDays, 'next_week' => $nextWeek];
    }
}
