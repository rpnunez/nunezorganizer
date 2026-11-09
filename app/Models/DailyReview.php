<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One scheduled local-day check-in for a single user.
 *
 * @property string $id
 * @property string $account_id
 * @property int $user_id
 * @property string $local_date
 * @property string $kind
 * @property string $status
 * @property string|null $delivery_id
 * @property CarbonInterface|null $sent_at
 */
class DailyReview extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['user_id', 'local_date', 'kind', 'status', 'sent_at', 'delivery_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
