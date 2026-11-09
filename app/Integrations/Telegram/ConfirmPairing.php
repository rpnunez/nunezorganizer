<?php

namespace App\Integrations\Telegram;

use App\Domain\Accounts\TenantContext;
use App\Domain\Audit\Audit;
use App\Models\PairingCode;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmPairing
{
    public function handle(User $user, string $code): TelegramLink
    {
        return DB::transaction(function () use ($user, $code): TelegramLink {
            $row = PairingCode::query()->where('user_id', $user->id)
                ->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();

            if ($row === null || $row->expires_at->isPast() || $row->used_at !== null
                || $row->claimed_telegram_user_id === null || $row->claimed_telegram_chat_id === null) {
                throw ValidationException::withMessages(['pairing' => __('Pairing code is invalid, expired or awaiting Telegram.')]);
            }

            $alreadyLinked = app(TenantContext::class)->withoutTenancy(
                fn () => TelegramLink::query()->where('telegram_user_id', $row->claimed_telegram_user_id)
                    ->where('user_id', '!=', $user->id)->exists(),
            );
            if ($alreadyLinked) {
                throw ValidationException::withMessages(['pairing' => __('This Telegram account is already linked.')]);
            }

            $link = TelegramLink::query()->firstOrNew(['user_id' => $user->id]);
            $link->fill([
                'telegram_user_id' => $row->claimed_telegram_user_id,
                'telegram_chat_id' => $row->claimed_telegram_chat_id,
                'status' => 'active',
                'linked_at' => now(),
                'revoked_at' => null,
            ])->save();
            $row->forceFill(['used_at' => now()])->save();
            Audit::record('telegram.linked', $link, actor: $user);

            return $link;
        });
    }
}
