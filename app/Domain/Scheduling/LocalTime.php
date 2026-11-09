<?php

namespace App\Domain\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Conversions between local wall-clock times and UTC instants (ADR 0003).
 *
 * DST policy:
 *  - A local time that does not exist (spring-forward gap) resolves to the first
 *    valid instant after the gap, i.e. the transition itself (02:30 -> 03:00).
 *  - A local time that occurs twice (fall-back) resolves to its FIRST occurrence.
 */
final class LocalTime
{
    /**
     * The user-local calendar date (Y-m-d) of an instant.
     */
    public static function localDate(CarbonInterface $instant, string $timezone): string
    {
        return $instant->copy()->setTimezone($timezone)->format('Y-m-d');
    }

    /**
     * UTC instant for a local wall-clock datetime ("2027-03-14 02:30" or "…T02:30:00").
     */
    public static function toUtc(string $localDateTime, string $timezone): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);

        // Naive components of what the user asked for, independent of any zone rules.
        $requested = CarbonImmutable::parse($localDateTime, 'UTC');
        $wall = $requested->format('Y-m-d H:i:s');

        $resolved = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $wall, $zone);

        if ($resolved === null) {
            throw new InvalidArgumentException("Invalid local datetime [{$localDateTime}].");
        }

        // Spring-forward gap: PHP pushes a non-existent time forward by the gap length
        // (02:30 -> 03:30). Policy is "next valid minute", i.e. the transition instant.
        if ($resolved->format('Y-m-d H:i:s') !== $wall) {
            $transition = self::lastTransitionAtOrBefore($zone, $resolved->getTimestamp());

            return CarbonImmutable::createFromTimestampUTC($transition ?? $resolved->getTimestamp());
        }

        // Fall-back overlap: make the "first occurrence" choice explicit rather than
        // relying on PHP's default resolution.
        $earlier = $resolved->subHour();
        if ($earlier->setTimezone($zone)->format('Y-m-d H:i:s') === $wall) {
            $resolved = $earlier;
        }

        return $resolved->utc();
    }

    /**
     * UTC instant for a local date and "HH:MM" time.
     */
    public static function dateAndTimeToUtc(string $date, string $time, string $timezone): CarbonImmutable
    {
        return self::toUtc("{$date} {$time}", $timezone);
    }

    private static function lastTransitionAtOrBefore(DateTimeZone $zone, int $timestamp): ?int
    {
        $transitions = $zone->getTransitions($timestamp - 86400 * 2, $timestamp);

        $candidates = array_filter($transitions, fn (array $t) => $t['ts'] <= $timestamp);

        return $candidates === [] ? null : (int) end($candidates)['ts'];
    }
}
