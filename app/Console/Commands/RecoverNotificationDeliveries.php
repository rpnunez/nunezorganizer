<?php

namespace App\Console\Commands;

use App\Domain\Accounts\TenantContext;
use App\Models\DailyReview;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverNotificationDeliveries extends Command
{
    protected $signature = 'nunez:recover-deliveries';

    protected $description = 'Classify notification deliveries left sending by a stopped worker';

    public function handle(TenantContext $tenant): int
    {
        $rows = DB::table('notification_deliveries')
            ->where('status', 'sending')->where('updated_at', '<=', now()->subMinutes(10))
            ->get(['id', 'user_id', 'account_id', 'kind']);
        $classified = 0;

        foreach ($rows as $row) {
            $user = User::find((int) $row->user_id);
            if ($user === null) {
                continue;
            }

            try {
                $classified += $tenant->runAs($user, function () use ($row): int {
                    return DB::transaction(function () use ($row): int {
                        $delivery = NotificationDelivery::query()->whereKey($row->id)->lockForUpdate()->first();
                        if ($delivery === null || $delivery->status !== 'sending'
                            || $delivery->updated_at->isAfter(now()->subMinutes(10))) {
                            return 0;
                        }

                        $status = $delivery->external_message_id ? 'sent' : 'uncertain';
                        $delivery->forceFill([
                            'status' => $status,
                            'last_error_code' => $status === 'uncertain' ? 'uncertain_send' : null,
                        ])->save();
                        if ($row->kind === 'daily_review') {
                            DailyReview::query()->whereKey($delivery->daily_review_id)->update(['status' => $status]);
                        }

                        return 1;
                    });
                }, (string) $row->account_id);
            } catch (AuthorizationException) {
                // Membership was revoked after queueing.
            }
        }

        $this->info("Classified {$classified} stale delivery attempt(s).");

        return self::SUCCESS;
    }
}
