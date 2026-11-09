<?php

namespace App\Integrations\Telegram;

use App\Domain\Accounts\TenantContext;
use App\Domain\Audit\Audit;
use App\Models\PairingCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePairingCode
{
    public function handle(User $user): string
    {
        abort_unless(app(TenantContext::class)->user()?->id === $user->id, 403);

        return DB::transaction(function () use ($user): string {
            PairingCode::query()->where('user_id', $user->id)->whereNull('used_at')->delete();

            $code = Str::random(32);
            $row = PairingCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => hash('sha256', $code),
                'expires_at' => now()->addMinutes(10),
            ]);
            Audit::record('telegram.pairing_requested', $row, actor: $user);

            return $code;
        });
    }
}
