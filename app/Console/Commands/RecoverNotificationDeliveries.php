<?php

namespace App\Console\Commands;

use App\Domain\Accounts\TenantContext;
use App\Domain\Notifications\Jobs\SendCompletionPrompt;
use App\Domain\Notifications\Jobs\SendDailyReview;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverNotificationDeliveries extends Command
{
    protected $signature = 'nunez:recover-deliveries';

    protected $description = 'Requeue notification deliveries left sending by a stopped worker';

    public function handle(TenantContext $tenant): int
    {
        $rows = DB::table('notification_deliveries')
            ->where('status', 'sending')->where('updated_at', '<=', now()->subMinutes(10))
            ->get(['id', 'user_id', 'account_id', 'kind']);
        $recovered = 0;

        foreach ($rows as $row) {
            $user = User::find((int) $row->user_id);
            if ($user === null) {
                continue;
            }

            try {
                $recovered += $tenant->runAs($user, function () use ($row, $user): int {
                    $ready = DB::transaction(function () use ($row): bool {
                        $delivery = NotificationDelivery::query()->whereKey($row->id)->lockForUpdate()->first();
                        if ($delivery === null || $delivery->status !== 'sending'
                            || $delivery->updated_at->isAfter(now()->subMinutes(10))) {
                            return false;
                        }

                        $delivery->forceFill(['status' => $delivery->external_message_id ? 'sent' : 'pending'])->save();

                        return $delivery->external_message_id === null;
                    });

                    if ($ready) {
                        if ($row->kind === 'daily_review') {
                            SendDailyReview::dispatch((string) $row->id, $user->id, (string) $row->account_id)->onQueue('notifications');
                        } else {
                            SendCompletionPrompt::dispatch((string) $row->id, $user->id, (string) $row->account_id)->onQueue('notifications');
                        }
                    }

                    return $ready ? 1 : 0;
                }, (string) $row->account_id);
            } catch (AuthorizationException) {
                // Membership was revoked after queueing.
            }
        }

        $this->info("Recovered {$recovered} delivery job(s).");

        return self::SUCCESS;
    }
}
