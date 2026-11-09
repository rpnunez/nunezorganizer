<?php

namespace App\Integrations\Telegram;

use App\Domain\Notifications\DeliveryResult;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Reviews\DailyReviewContent;
use App\Models\DailyReview;
use App\Models\NotificationAction;
use App\Models\NotificationDelivery;
use App\Models\TaskOccurrence;
use App\Models\TelegramLink;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Private-chat completion prompts with one-time Yes/No callback tokens. */
class TelegramChannel implements NotificationChannel
{
    public function __construct(private readonly TelegramClient $client) {}

    public function availableFor(User $user): bool
    {
        return config('services.telegram.token') !== null
            && TelegramLink::query()->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    public function sendCompletionPrompt(User $user, TaskOccurrence $occurrence, NotificationDelivery $delivery): DeliveryResult
    {
        $link = TelegramLink::query()->where('user_id', $user->id)->where('status', 'active')->first();
        if ($link === null) {
            return DeliveryResult::skipped();
        }

        [$yes, $no] = DB::transaction(function () use ($delivery, $user): array {
            NotificationAction::query()->where('delivery_id', $delivery->id)->delete();
            $tokens = [];
            foreach (['yes', 'no'] as $action) {
                $token = Str::random(24);
                NotificationAction::query()->create([
                    'delivery_id' => $delivery->id,
                    'user_id' => $user->id,
                    'action' => $action,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addDays(7),
                ]);
                $tokens[] = $token;
            }

            return $tokens;
        });

        $title = $occurrence->task->title;
        $messageId = $this->client->sendMessage($link->telegram_chat_id,
            __('Did you finish ":title"? (:time, :timezone)', [
                'title' => $title,
                'time' => $occurrence->scheduled_end_at->setTimezone($occurrence->timezone)->format('g:i A'),
                'timezone' => $occurrence->timezone,
            ]), [
                'reply_markup' => ['inline_keyboard' => [[
                    ['text' => '✅ Yes', 'callback_data' => $yes],
                    ['text' => '❌ No', 'callback_data' => $no],
                ]]],
            ]);

        return $messageId === null ? DeliveryResult::skipped() : DeliveryResult::sent($messageId);
    }

    public function sendDailyReview(User $user, DailyReview $review, NotificationDelivery $delivery): DeliveryResult
    {
        $link = TelegramLink::query()->where('user_id', $user->id)->where('status', 'active')->first();
        if ($link === null) {
            return DeliveryResult::skipped();
        }

        $timezone = UserPreference::query()->where('user_id', $user->id)->value('timezone') ?: 'UTC';
        $text = app(DailyReviewContent::class)->build($user, $review, $timezone);
        $messageId = $this->client->sendMessage($link->telegram_chat_id, $text);

        return $messageId === null ? DeliveryResult::skipped() : DeliveryResult::sent($messageId);
    }
}
