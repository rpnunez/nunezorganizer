<?php

namespace App\Domain\Scheduling;

use App\Enums\Punctuality;
use Carbon\CarbonInterface;

/**
 * Classify a completion against the occurrence's PLANNED end (never the time a
 * notification was sent). Boundaries are inclusive:
 *   completed <= end               -> on_time
 *   completed <= end + grace       -> within_grace
 *   otherwise                      -> late
 */
final class PunctualityClassifier
{
    public static function classify(CarbonInterface $completedAt, CarbonInterface $plannedEnd, int $graceMinutes): Punctuality
    {
        if ($completedAt->lessThanOrEqualTo($plannedEnd)) {
            return Punctuality::OnTime;
        }

        if ($completedAt->lessThanOrEqualTo($plannedEnd->copy()->addMinutes(max(0, $graceMinutes)))) {
            return Punctuality::WithinGrace;
        }

        return Punctuality::Late;
    }
}
