<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $telegram_user_id */
class TelegramLink extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['user_id', 'telegram_user_id', 'telegram_chat_id', 'status', 'linked_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['telegram_user_id' => 'integer', 'telegram_chat_id' => 'integer', 'linked_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
