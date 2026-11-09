<?php

namespace App\Domain\Reviews;

use App\Domain\Scheduling\LocalTime;
use Carbon\CarbonImmutable;

class QuietHours
{
    /** @param array{start: string, end: string}|null $quiet */
    public static function secondsUntilEnd(CarbonImmutable $now, string $timezone, ?array $quiet): int
    {
        if ($quiet === null) {
            return 0;
        }
        $local = $now->setTimezone($timezone);
        $time = $local->format('H:i');
        $start = $quiet['start'];
        $end = $quiet['end'];
        $active = $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
        if (! $active) {
            return 0;
        }

        $endDate = $local->format('Y-m-d');
        if ($start > $end && $time >= $start) {
            $endDate = $local->addDay()->format('Y-m-d');
        }
        $endAt = LocalTime::dateAndTimeToUtc($endDate, $end, $timezone);

        return max(1, (int) ceil($now->diffInSeconds($endAt)));
    }
}
