<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user routine and notification preferences. Local wall-clock values
 * ("19:00", weekday keys) are stored as-is; never precompute UTC (ADR 0003).
 *
 * @property string $id
 * @property int $user_id
 * @property string $account_id
 * @property string $timezone
 * @property int $grace_minutes
 * @property int $unanswered_after_minutes
 * @property array<string, list<array{start: string, end: string}>>|null $work_windows
 * @property array{start: string, end: string}|null $quiet_hours
 * @property array<string, string|null>|null $review_times
 * @property array<string, mixed>|null $notification_preferences
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class UserPreference extends Model
{
    use BelongsToAccount, HasUlids;

    public const int DEFAULT_GRACE_MINUTES = 15;

    public const int DEFAULT_UNANSWERED_AFTER_MINUTES = 720;

    /**
     * account_id and user_id are intentionally NOT fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'timezone',
        'grace_minutes',
        'unanswered_after_minutes',
        'work_windows',
        'quiet_hours',
        'review_times',
        'notification_preferences',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
            'unanswered_after_minutes' => 'integer',
            'work_windows' => 'array',
            'quiet_hours' => 'array',
            'review_times' => 'array',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
