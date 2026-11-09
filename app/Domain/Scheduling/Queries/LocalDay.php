<?php

namespace App\Domain\Scheduling\Queries;

use App\Domain\Scheduling\LocalTime;
use Carbon\CarbonImmutable;

/**
 * A user-local calendar day expressed as a half-open UTC interval [start, end).
 * DST-safe: a 23- or 25-hour day is handled because both bounds are computed locally.
 */
final readonly class LocalDay
{
    private function __construct(
        public string $date,
        public string $timezone,
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtc,
    ) {}

    public static function of(string $date, string $timezone): self
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') ?: CarbonImmutable::parse($date, 'UTC');
        $next = $day->addDay()->format('Y-m-d');

        return new self(
            $day->format('Y-m-d'),
            $timezone,
            LocalTime::toUtc($day->format('Y-m-d').' 00:00', $timezone),
            LocalTime::toUtc($next.' 00:00', $timezone),
        );
    }

    public static function today(string $timezone): self
    {
        return self::of(LocalTime::localDate(now(), $timezone), $timezone);
    }
}
