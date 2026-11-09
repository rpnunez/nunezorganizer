<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Only a hash of the user-visible pairing code is stored.
 *
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $used_at
 * @property int|null $claimed_telegram_user_id
 * @property int|null $claimed_telegram_chat_id
 * @property int $attempts
 * @property int $user_id
 * @property string $account_id
 */
class PairingCode extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = [
        'user_id', 'code_hash', 'expires_at', 'used_at', 'attempts',
        'claimed_telegram_user_id', 'claimed_telegram_chat_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer',
            'claimed_telegram_user_id' => 'integer', 'claimed_telegram_chat_id' => 'integer',
        ];
    }
}
