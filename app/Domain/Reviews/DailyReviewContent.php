<?php

namespace App\Domain\Reviews;

use App\Domain\Scheduling\Queries\BacklogQuery;
use App\Domain\Scheduling\Queries\LocalDay;
use App\Domain\Scheduling\Queries\TodayQuery;
use App\Models\DailyReview;
use App\Models\RoutinePlan;
use App\Models\User;
use Carbon\CarbonImmutable;

class DailyReviewContent
{
    public function build(User $user, DailyReview $review, string $timezone): string
    {
        $day = LocalDay::of($review->local_date, $timezone);
        $backlog = app(BacklogQuery::class)->count($user, $day);
        $blocks = app(TodayQuery::class)->blocks($user, $day);
        $done = $blocks->filter(fn ($block) => $block->state->value === 'finished')->count();
        $plan = $blocks->take(5)->map(fn ($block) => '• '.$block->task->title)->implode("\n");

        $message = match ($review->kind) {
            'morning' => "Morning check-in: {$blocks->count()} block(s) today, {$backlog} in backlog."
                .($plan === '' ? '' : "\n".$plan)."\nWant to reschedule anything? Open ".route('dashboard'),
            'afternoon' => "Afternoon check-in: {$done} of {$blocks->count()} planned block(s) done. {$backlog} in backlog.",
            default => "Evening check-in: {$done} of {$blocks->count()} planned block(s) done. {$backlog} in backlog. Plan tomorrow: ".route('dashboard'),
        };

        if ($review->kind === 'evening' && $day->date === CarbonImmutable::parse($day->date, $timezone)->endOfWeek()->format('Y-m-d')) {
            $plan = RoutinePlan::query()->where('user_id', $user->id)->where('status', 'active')->first();
            if ($plan !== null) {
                $suggestion = app(RoutinePlanService::class)->suggest($user, $plan, $day->date);
                $message .= "\n".$suggestion['reason'].' '.route('progress');
            }
        }

        return $message;
    }
}
