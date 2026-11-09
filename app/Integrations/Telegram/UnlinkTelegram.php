<?php

namespace App\Integrations\Telegram;

use App\Domain\Audit\Audit;
use App\Models\TelegramLink;
use App\Models\User;

class UnlinkTelegram
{
    public function handle(User $user): void
    {
        $link = TelegramLink::query()->where('user_id', $user->id)->first();
        if ($link === null || $link->status === 'revoked') {
            return;
        }

        $link->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        Audit::record('telegram.unlinked', $link, actor: $user);
    }
}
