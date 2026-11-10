<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Accounts\Jobs\TenantAware;
use App\Domain\Accounts\Jobs\WithTenantContext;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Reviews\QuietHours;
use App\Integrations\Telegram\TelegramPermanentFailure;
use App\Integrations\Telegram\TelegramRateLimited;
use App\Models\DailyReview;
use App\Models\NotificationDelivery;
use App\Models\TelegramLink;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendDailyReview implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $deliveryId, public readonly int $userId, public readonly string $accountId) {}

    public function tenantUserId(): int
    {
        return $this->userId;
    }

    public function tenantAccountId(): string
    {
        return $this->accountId;
    }

    /** @return list<WithTenantContext> */
    public function middleware(): array
    {
        return [new WithTenantContext];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90, 300];
    }

    public function handle(NotificationChannel $channel): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        $review = $delivery === null ? null : DailyReview::query()->find($delivery->daily_review_id);
        $user = User::find($this->userId);
        $pref = UserPreference::query()->where('user_id', $this->userId)->first();
        if ($delivery === null || $review === null || $user === null || $pref === null
            || $delivery->account_id !== $this->accountId || $delivery->user_id !== $user->id
            || $review->user_id !== $user->id || $delivery->status !== 'pending') {
            return;
        }

        if (! ($pref->notification_preferences['daily_reviews'] ?? true) || ! $channel->availableFor($user)) {
            $delivery->forceFill(['status' => 'skipped'])->save();
            $review->forceFill(['status' => 'skipped'])->save();

            return;
        }

        $quietSeconds = QuietHours::secondsUntilEnd(CarbonImmutable::instance(now()), $pref->timezone, $pref->quiet_hours);
        if ($quietSeconds > 0) {
            $this->release($quietSeconds);

            return;
        }

        $claimed = DB::transaction(function () use ($delivery): bool {
            $locked = NotificationDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'pending') {
                return false;
            }
            $locked->forceFill(['status' => 'sending', 'attempt_count' => $locked->attempt_count + 1])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }

        try {
            $result = $channel->sendDailyReview($user, $review, $delivery);
            $status = $result->sent ? 'sent' : 'skipped';
            $delivery->forceFill(['status' => $status, 'sent_at' => $result->sent ? now() : null,
                'external_message_id' => $result->externalMessageId, 'last_error_code' => null])->save();
            $review->forceFill(['status' => $status, 'sent_at' => $result->sent ? now() : null])->save();
        } catch (TelegramRateLimited $e) {
            $delivery->forceFill(['status' => 'pending', 'last_error_code' => 'rate_limited'])->save();
            $this->release($e->retryAfter);
        } catch (TelegramPermanentFailure $e) {
            $delivery->forceFill(['status' => 'failed', 'last_error_code' => $e->blocked ? 'blocked' : 'permanent'])->save();
            $review->forceFill(['status' => 'failed'])->save();
            if ($e->blocked) {
                TelegramLink::query()->where('user_id', $user->id)->update(['status' => 'blocked']);
            }
        } catch (Throwable $e) {
            $delivery->forceFill(['status' => 'uncertain', 'last_error_code' => 'uncertain_send'])->save();
            $review->forceFill(['status' => 'uncertain'])->save();
            throw $e;
        }
    }
}
