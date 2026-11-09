<?php

namespace App\Domain\Reviews;

use App\Domain\Scheduling\Queries\BacklogQuery;
use App\Domain\Scheduling\Queries\LocalDay;
use App\Enums\OccurrenceState;
use App\Enums\Punctuality;
use App\Enums\ResponseType;
use App\Models\TaskOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;

class WeeklyStats
{
    /** @return array<string, mixed> */
    public function forUser(User $user, string $weekStartLocal, string $timezone): array
    {
        $start = CarbonImmutable::parse($weekStartLocal, $timezone)->startOfWeek();
        $end = $start->addWeek();
        $startDay = LocalDay::of($start->format('Y-m-d'), $timezone);
        $endDay = LocalDay::of($end->format('Y-m-d'), $timezone);
        $occurrences = TaskOccurrence::query()
            ->whereHas('task', fn ($q) => $q->where('owner_user_id', $user->id))
            ->where('scheduled_start_at', '>=', $startDay->startUtc)
            ->where('scheduled_start_at', '<', $endDay->startUtc)
            ->with(['responses', 'task'])->orderBy('scheduled_start_at')->get();

        $counts = ['planned' => 0, 'completed' => 0, 'on_time' => 0, 'within_grace' => 0,
            'late' => 0, 'missed' => 0, 'unanswered' => 0, 'pending' => 0, 'cancelled' => 0];
        $days = [];
        for ($date = $start; $date < $end; $date = $date->addDay()) {
            $days[$date->format('Y-m-d')] = ['planned' => 0, 'completed' => 0];
        }

        foreach ($occurrences as $occurrence) {
            $date = $occurrence->scheduled_start_at->setTimezone($timezone)->format('Y-m-d');
            $counts['planned']++;
            $days[$date]['planned']++;
            $yes = $occurrence->responses->first(fn ($response) => $response->response === ResponseType::Yes);

            if ($occurrence->state === OccurrenceState::Finished && $yes !== null) {
                $counts['completed']++;
                $days[$date]['completed'] = ($days[$date]['completed'] ?? 0) + 1;
                if ($yes->punctuality === Punctuality::OnTime) {
                    $counts['on_time']++;
                } elseif ($yes->punctuality === Punctuality::WithinGrace) {
                    $counts['within_grace']++;
                } elseif ($yes->punctuality === Punctuality::Late) {
                    $counts['late']++;
                }
            } elseif ($occurrence->state === OccurrenceState::Missed) {
                $counts['missed']++;
            } elseif ($occurrence->state === OccurrenceState::Unanswered) {
                $counts['unanswered']++;
            } elseif ($occurrence->state === OccurrenceState::Cancelled) {
                $counts['cancelled']++;
            } else {
                $counts['pending']++;
            }
        }

        return ['week_start' => $start->format('Y-m-d'), 'timezone' => $timezone,
            ...$counts, 'open_overdue_tasks' => app(BacklogQuery::class)->count($user, LocalDay::today($timezone)),
            'days' => $days];
    }
}
