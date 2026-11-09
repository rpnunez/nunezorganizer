<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $account_id
 * @property int $user_id
 * @property string|null $occurrence_id
 * @property string $kind
 * @property string $channel
 * @property string $status
 * @property int|null $occurrence_version
 * @property int $attempt_count
 * @property string|null $external_message_id
 */
class NotificationDelivery extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = [
        'user_id', 'occurrence_id', 'daily_review_id', 'kind', 'channel', 'status', 'due_at',
        'sent_at', 'external_message_id', 'occurrence_version', 'attempt_count', 'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
            'occurrence_version' => 'integer',
            'attempt_count' => 'integer',
        ];
    }

    /** @return BelongsTo<TaskOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(TaskOccurrence::class, 'occurrence_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
