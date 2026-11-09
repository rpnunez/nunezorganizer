<?php

namespace App\Integrations\Telegram;

use App\Domain\Accounts\TenantContext;
use App\Models\PairingCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Telegram /start stages a private chat; the signed-in owner must confirm it. */
class StagePairing
{
    public function handle(string $code, int $telegramUserId, int $chatId): bool
    {
        if ($telegramUserId <= 0 || $chatId !== $telegramUserId || ! preg_match('/^[A-Za-z0-9]{32}$/', $code)) {
            return false;
        }

        $tenant = app(TenantContext::class);
        $candidate = $tenant->withoutTenancy(fn () => PairingCode::query()->where('code_hash', hash('sha256', $code))->first());
        $user = $candidate === null ? null : User::find($candidate->user_id);
        if ($user === null) {
            return false;
        }

        try {
            return $tenant->runAs($user, fn (): bool => DB::transaction(function () use ($candidate, $telegramUserId, $chatId): bool {
                $row = PairingCode::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if ($row === null || $row->used_at !== null || $row->expires_at->isPast() || $row->attempts >= 5) {
                    return false;
                }

                $row->attempts++;
                if ($row->claimed_telegram_user_id !== null && $row->claimed_telegram_user_id !== $telegramUserId) {
                    $row->save();

                    return false;
                }

                $row->claimed_telegram_user_id = $telegramUserId;
                $row->claimed_telegram_chat_id = $chatId;
                $row->save();

                return true;
            }), $candidate->account_id);
        } catch (AuthorizationException) {
            return false;
        }
    }
}
