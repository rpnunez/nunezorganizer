<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Scheduling\Events\OccurrenceChanged;
use Illuminate\Support\Facades\DB;

/** A changed occurrence version invalidates a prompt that has not been sent. */
class InvalidatePendingDelivery
{
    public function handle(OccurrenceChanged $event): void
    {
        DB::table('notification_deliveries')
            ->where('account_id', $event->accountId)
            ->where('occurrence_id', $event->occurrenceId)
            ->where('status', 'pending')
            ->where('occurrence_version', '<', $event->version)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }
}
