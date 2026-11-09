<?php

namespace App\Integrations\Telegram;

use App\Domain\Accounts\TenantContext;
use App\Domain\Scheduling\Actions\RecordResponse;
use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Enums\TaskStatus;
use App\Models\NotificationAction;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HandleTelegramCallback
{
    public function __construct(private readonly TelegramClient $client) {}

    /** @param array<string, mixed> $query */
    public function handle(array $query): void
    {
        $callbackId = (string) ($query['id'] ?? '');
        if ($callbackId !== '') {
            // Telegram requires a prompt callback answer even for stale or invalid buttons.
            try {
                $this->client->answerCallback($callbackId);
            } catch (RuntimeException) {
                // The state transition can still proceed if Telegram is temporarily unavailable.
            }
        }

        $token = (string) ($query['data'] ?? '');
        $fromId = (int) ($query['from']['id'] ?? 0);
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);
        if ($token === '' || $fromId <= 0 || $chatId !== $fromId || $messageId <= 0
            || ($query['message']['chat']['type'] ?? null) !== 'private') {
            return;
        }

        $tenant = app(TenantContext::class);
        $candidate = $tenant->withoutTenancy(fn () => NotificationAction::query()
            ->where('token_hash', hash('sha256', $token))->first());
        $user = $candidate === null ? null : User::find($candidate->user_id);
        if ($user === null) {
            return;
        }

        try {
            $text = $tenant->runAs($user,
                fn () => $this->respond($candidate->id, $user, $fromId, $chatId), $candidate->account_id);
        } catch (AuthorizationException) {
            return;
        }

        if ($text !== null) {
            $this->client->editMessage($chatId, $messageId, $text);
        }
    }

    private function respond(string $actionId, User $user, int $fromId, int $chatId): ?string
    {
        $action = NotificationAction::query()->whereKey($actionId)->first();
        $delivery = $action === null ? null : NotificationDelivery::query()->whereKey($action->delivery_id)->first();
        $occurrence = $delivery === null ? null : TaskOccurrence::query()->whereKey($delivery->occurrence_id)->first();
        $task = $occurrence === null ? null : Task::query()->whereKey($occurrence->task_id)->first();
        $link = TelegramLink::query()->where('user_id', $user->id)->where('status', 'active')->first();

        if ($action === null || $delivery === null || $occurrence === null || $task === null || $link === null
            || $action->user_id !== $user->id || $delivery->user_id !== $user->id
            || $task->owner_user_id !== $user->id || $link->telegram_user_id !== $fromId
            || $link->telegram_chat_id !== $chatId || $delivery->status !== 'sent'
            || $action->consumed_at !== null || $action->expires_at->isPast()) {
            return null;
        }

        return DB::transaction(function () use ($task, $occurrence, $action): string {
            $lockedTask = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $lockedOccurrence = TaskOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
            $lockedAction = NotificationAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();

            if ($lockedAction->consumed_at !== null) {
                return __('Already answered.');
            }

            $delivery = NotificationDelivery::query()->whereKey($lockedAction->delivery_id)->firstOrFail();
            if ($lockedTask->status !== TaskStatus::Open || $lockedOccurrence->version !== $delivery->occurrence_version
                || $lockedOccurrence->state->isTerminal()) {
                $result = __('This block changed. Open the app for its current state.');
            } else {
                $type = $lockedAction->action === 'yes' ? ResponseType::Yes : ResponseType::No;
                try {
                    app(RecordResponse::class)->handle($lockedTask, $lockedOccurrence, $type,
                        $user = User::findOrFail($lockedAction->user_id), ResponseChannel::Telegram,
                        'telegram:'.$lockedAction->id);
                    $result = $type === ResponseType::Yes
                        ? __('✅ Completed. Your task is done.')
                        : __('❌ Not done. Your task remains in the backlog.');
                } catch (TaskStateConflict) {
                    $result = __('This block changed. Open the app for its current state.');
                }
            }

            NotificationAction::query()->where('delivery_id', $lockedAction->delivery_id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);

            return $result;
        });
    }
}
