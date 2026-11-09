<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Human-friendly rendering of UTC instants in a user's timezone.
 */
final class LocalClock
{
    public static function time(?CarbonInterface $instant, string $timezone): string
    {
        return $instant?->copy()->setTimezone($timezone)->format('g:i A') ?? '';
    }

    public static function dayAndTime(?CarbonInterface $instant, string $timezone): string
    {
        if ($instant === null) {
            return '';
        }

        $local = $instant->copy()->setTimezone($timezone);
        $today = now()->setTimezone($timezone);

        $day = match (true) {
            $local->isSameDay($today) => __('Today'),
            $local->isSameDay($today->subDay()) => __('Yesterday'),
            $local->isSameDay($today->addDay()) => __('Tomorrow'),
            default => $local->format('D, M j'),
        };

        return $day.' · '.$local->format('g:i A');
    }

    public static function range(CarbonInterface $start, CarbonInterface $end, string $timezone): string
    {
        return self::dayAndTime($start, $timezone).'–'.self::time($end, $timezone);
    }

    public static function relativeDays(CarbonInterface $since, string $timezone): string
    {
        $days = (int) $since->copy()->setTimezone($timezone)->startOfDay()
            ->diffInDays(now()->setTimezone($timezone)->startOfDay());

        return match (true) {
            $days <= 0 => __('today'),
            $days === 1 => __('1 day'),
            default => __(':n days', ['n' => $days]),
        };
    }
}
