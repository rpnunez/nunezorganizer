<?php

namespace Tests\Unit;

use App\Domain\Scheduling\PunctualityClassifier;
use App\Enums\Punctuality;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T13: on-time / within-grace / late, measured from the planned end. Boundaries inclusive.
 */
class PunctualityClassifierTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: Punctuality}>
     */
    public static function cases(): array
    {
        return [
            'well before end' => ['2026-11-09 15:05:00', 15, Punctuality::OnTime],
            'exactly at end' => ['2026-11-09 15:15:00', 15, Punctuality::OnTime],
            'one second after end' => ['2026-11-09 15:15:01', 15, Punctuality::WithinGrace],
            'exactly end + grace' => ['2026-11-09 15:30:00', 15, Punctuality::WithinGrace],
            'end + grace + 1s' => ['2026-11-09 15:30:01', 15, Punctuality::Late],
            'next day' => ['2026-11-10 09:00:00', 15, Punctuality::Late],
            'zero grace, 1s late' => ['2026-11-09 15:15:01', 0, Punctuality::Late],
            'custom 30m grace' => ['2026-11-09 15:44:59', 30, Punctuality::WithinGrace],
        ];
    }

    #[DataProvider('cases')]
    public function test_classification(string $completedAt, int $grace, Punctuality $expected): void
    {
        $plannedEnd = CarbonImmutable::parse('2026-11-09 15:15:00', 'UTC');

        $this->assertSame($expected, PunctualityClassifier::classify(CarbonImmutable::parse($completedAt, 'UTC'), $plannedEnd, $grace));
    }
}
