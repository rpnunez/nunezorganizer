<?php

namespace App\Console\Commands;

use App\Domain\Accounts\TenantContext;
use App\Domain\Notifications\Jobs\SendDailyReview;
use App\Domain\Reviews\QuietHours;
use App\Domain\Scheduling\LocalTime;
use App\Models\DailyReview;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchDailyReviews extends Command
{
    protected $signature = 'nunez:dispatch-reviews';

    protected $description = 'Queue enabled daily reviews at each user’s local time';

    public function handle(TenantContext $tenant): int
    {
        $queued = 0;
        $preferences = $tenant->withoutTenancy(fn () => UserPreference::query()->select(['user_id', 'account_id'])->get());
        foreach ($preferences as $preference) {
            $user = User::find($preference->user_id);
            if ($user === null) {
                continue;
            }
            try {
                $queued += $tenant->runAs($user, fn () => $this->dispatchFor($user), $preference->account_id);
            } catch (AuthorizationException) {
                // Revoked membership is no longer eligible.
            }
        }
        $this->info("Queued {$queued} daily review(s).");

        return self::SUCCESS;
    }

    private function dispatchFor(User $user): int
    {
        $preference = UserPreference::query()->where('user_id', $user->id)->firstOrFail();
        $times = $preference->review_times ?? [];
        if (! ($preference->notification_preferences['daily_reviews'] ?? true)) {
            return 0;
        }

        $zone = $preference->timezone;
        $now = CarbonImmutable::instance(now());
        $local = $now->setTimezone($zone);
        $date = $local->format('Y-m-d');
        if (QuietHours::secondsUntilEnd($now, $zone, $preference->quiet_hours) > 0) {
            return 0;
        }

        $count = 0;
        foreach (['morning', 'afternoon', 'evening'] as $kind) {
            $time = $times[$kind] ?? null;
            if ($time === null || $now->lessThan(LocalTime::dateAndTimeToUtc($date, $time, $zone))) {
                continue;
            }

            $count += DB::transaction(function () use ($date, $kind, $user, $preference): int {
                $id = (string) Str::ulid();
                $inserted = DB::table('daily_reviews')->insertOrIgnore([
                    'id' => $id, 'account_id' => $preference->account_id, 'user_id' => $user->id,
                    'local_date' => $date, 'kind' => $kind, 'status' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($inserted === 0) {
                    return 0;
                }
                $review = DailyReview::query()->findOrFail($id);
                $delivery = NotificationDelivery::query()->create([
                    'user_id' => $user->id, 'daily_review_id' => $id,
                    'kind' => 'daily_review', 'channel' => 'telegram', 'status' => 'pending',
                    'due_at' => now(),
                ]);
                $review->forceFill(['delivery_id' => $delivery->id])->save();
                SendDailyReview::dispatch($delivery->id, $user->id, $preference->account_id)->onQueue('notifications')->afterCommit();

                return 1;
            });
        }

        return $count;
    }
}
