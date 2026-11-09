<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Opaque callback token hashes; plaintext tokens are never persisted.
 *
 * @property string $id
 * @property string $delivery_id
 * @property string $action
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property int $user_id
 */
class NotificationAction extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['delivery_id', 'user_id', 'action', 'token_hash', 'expires_at', 'consumed_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
