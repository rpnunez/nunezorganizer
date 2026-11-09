<?php

namespace App\Integrations\Telegram;

use App\Domain\Accounts\TenantContext;
use App\Domain\Scheduling\Queries\BacklogQuery;
use App\Domain\Scheduling\Queries\LocalDay;
use App\Domain\Scheduling\Queries\TodayQuery;
use App\Models\TelegramLink;
use App\Models\User;
use App\Models\UserPreference;

class TelegramCommands
{
    public function __construct(private readonly TelegramClient $client) {}

    public function handle(string $command, int $fromId, int $chatId): void
    {
        if ($fromId <= 0 || $fromId !== $chatId) {
            return;
        }

        $tenant = app(TenantContext::class);
        $link = $tenant->withoutTenancy(fn () => TelegramLink::query()
            ->where('telegram_user_id', $fromId)->where('telegram_chat_id', $chatId)
            ->where('status', 'active')->first());
        $user = $link === null ? null : User::find($link->user_id);
        if ($user === null) {
            return;
        }

        $text = $tenant->runAs($user, function () use ($command, $user): string {
            if ($command === '/unlink') {
                app(UnlinkTelegram::class)->handle($user);

                return __('Telegram disconnected. Reconnect in NunezOrganizer Settings.');
            }

            $timezone = UserPreference::query()->where('user_id', $user->id)->value('timezone') ?: 'UTC';
            $day = LocalDay::today($timezone);
            if ($command === '/backlog') {
                $tasks = app(BacklogQuery::class)->query($user, $day)->limit(10)->get();

                return $tasks->isEmpty() ? __('Your backlog is empty.')
                    : __('Backlog:')."\n".$tasks->map(fn ($task) => '• '.$task->title)->implode("\n");
            }

            $blocks = app(TodayQuery::class)->blocks($user, $day);

            return $blocks->isEmpty() ? __('No blocks scheduled today.')
                : __('Today:')."\n".$blocks->take(10)->map(fn ($block) => '• '
                    .$block->scheduled_start_at->setTimezone($timezone)->format('g:i A').' '.$block->task->title)->implode("\n");
        }, $link->account_id);

        $this->client->sendMessage($chatId, $text);
    }
}
