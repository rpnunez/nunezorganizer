<?php

namespace Tests\Unit;

use App\Domain\Scheduling\LocalTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0003 DST policy (T14 domain part).
 */
class LocalTimeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function conversions(): array
    {
        return [
            // America/New_York: spring forward 2027-03-14 02:00 -> 03:00
            'NY before gap' => ['2027-03-14 01:59', 'America/New_York', '2027-03-14T06:59:00Z'],
            'NY inside gap -> transition (03:00 EDT)' => ['2027-03-14 02:30', 'America/New_York', '2027-03-14T07:00:00Z'],
            'NY first valid minute' => ['2027-03-14 03:00', 'America/New_York', '2027-03-14T07:00:00Z'],
            // America/New_York: fall back 2026-11-01 02:00 -> 01:00 (01:xx happens twice)
            'NY ambiguous -> first occurrence (EDT)' => ['2026-11-01 01:30', 'America/New_York', '2026-11-01T05:30:00Z'],
            'NY after overlap' => ['2026-11-01 02:30', 'America/New_York', '2026-11-01T07:30:00Z'],
            // Europe/Madrid
            'Madrid summer' => ['2026-07-01 09:00', 'Europe/Madrid', '2026-07-01T07:00:00Z'],
            'Madrid inside gap' => ['2027-03-28 02:15', 'Europe/Madrid', '2027-03-28T01:00:00Z'],
            'Madrid ambiguous -> first (CEST)' => ['2026-10-25 02:30', 'Europe/Madrid', '2026-10-25T00:30:00Z'],
            // No DST
            'Tokyo' => ['2026-11-09 19:00', 'Asia/Tokyo', '2026-11-09T10:00:00Z'],
        ];
    }

    #[DataProvider('conversions')]
    public function test_local_to_utc(string $local, string $zone, string $expectedUtc): void
    {
        $this->assertSame($expectedUtc, LocalTime::toUtc($local, $zone)->toIso8601ZuluString());
    }

    public function test_date_and_time_helper(): void
    {
        $this->assertSame('2026-11-09T15:45:00Z', LocalTime::dateAndTimeToUtc('2026-11-09', '10:45', 'America/New_York')->toIso8601ZuluString());
    }

    public function test_local_date_depends_on_the_users_zone(): void
    {
        $instant = CarbonImmutable::parse('2026-11-10T03:30:00Z');

        $this->assertSame('2026-11-09', LocalTime::localDate($instant, 'America/New_York'));
        $this->assertSame('2026-11-10', LocalTime::localDate($instant, 'Europe/Madrid'));
        $this->assertSame('2026-11-10', LocalTime::localDate($instant, 'UTC'));
    }
}
