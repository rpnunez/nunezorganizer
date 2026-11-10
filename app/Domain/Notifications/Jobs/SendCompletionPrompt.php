<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Accounts\Jobs\TenantAware;
use App\Domain\Accounts\Jobs\WithTenantContext;
use App\Domain\Notifications\NotificationChannel;
use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Integrations\Telegram\TelegramPermanentFailure;
use App\Integrations\Telegram\TelegramRateLimited;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\TelegramLink;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Re-checks tenant, task and occurrence state before any external send. */
class SendCompletionPrompt implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $deliveryId,
        public readonly int $userId,
        public readonly string $accountId,
    ) {}

    public function tenantUserId(): int
    {
        return $this->userId;
    }

    public function tenantAccountId(): string
    {
        return $this->accountId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90, 300];
    }

    /** @return list<WithTenantContext> */
    public function middleware(): array
    {
        return [new WithTenantContext];
    }

    public function handle(NotificationChannel $channel): void
    {
        $delivery = NotificationDelivery::query()->whereKey($this->deliveryId)->first();
        $user = User::find($this->userId);
        if ($delivery === null || $user === null || $delivery->account_id !== $this->accountId
            || $delivery->user_id !== $user->id || $delivery->status !== 'pending') {
            return;
        }

        $occurrence = TaskOccurrence::query()->whereKey($delivery->occurrence_id)->first();
        $task = $occurrence === null ? null : Task::query()->whereKey($occurrence->task_id)->first();
        $preferences = UserPreference::query()->where('user_id', $user->id)->first();
        if ($occurrence === null || $task === null || $task->owner_user_id !== $user->id
            || $task->status !== TaskStatus::Open || $occurrence->state !== OccurrenceState::AwaitingResponse
            || $occurrence->version !== $delivery->occurrence_version
            || ! ($preferences->notification_preferences['completion_prompts'] ?? true)
            || ! $channel->availableFor($user)) {
            $delivery->forceFill(['status' => 'skipped'])->save();

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

        $delivery->refresh();

        try {
            $result = $channel->sendCompletionPrompt($user, $occurrence, $delivery);
            $delivery->forceFill([
                'status' => $result->sent ? 'sent' : 'skipped',
                'sent_at' => $result->sent ? now() : null,
                'external_message_id' => $result->externalMessageId,
                'last_error_code' => null,
            ])->save();
        } catch (TelegramRateLimited $e) {
            $delivery->forceFill(['status' => 'pending', 'last_error_code' => 'rate_limited'])->save();
            $this->release($e->retryAfter);
        } catch (TelegramPermanentFailure $e) {
            $delivery->forceFill(['status' => 'failed', 'last_error_code' => $e->blocked ? 'blocked' : 'permanent'])->save();
            if ($e->blocked) {
                TelegramLink::query()->where('user_id', $user->id)->update(['status' => 'blocked']);
            }
        } catch (Throwable $e) {
            DB::transaction(function () use ($delivery): void {
                $delivery->forceFill(['status' => 'uncertain', 'last_error_code' => 'uncertain_send'])->save();
            });

            throw $e;
        }
    }
}
