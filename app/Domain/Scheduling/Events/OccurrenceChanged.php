<?php

namespace App\Domain\Scheduling\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after an occurrence is rescheduled or cancelled (its version was bumped).
 * The notification engine (#14) listens to invalidate pending deliveries.
 * Carries IDs only; listeners must re-load and re-authorize.
 */
final class OccurrenceChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $occurrenceId,
        public readonly string $accountId,
        public readonly int $version,
        public readonly string $change, // 'rescheduled' | 'cancelled'
    ) {}
}
