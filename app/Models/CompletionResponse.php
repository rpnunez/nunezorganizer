<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use App\Enums\Punctuality;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only log of what a user reported for a task/occurrence.
 * Written only by the completion service (#9).
 *
 * @property string $id
 * @property string $account_id
 * @property string $task_id
 * @property string|null $occurrence_id
 * @property int|null $user_id
 * @property ResponseType $response
 * @property CarbonInterface $response_at
 * @property CarbonInterface|null $completed_at
 * @property Punctuality|null $punctuality
 * @property ResponseChannel $channel
 * @property string $idempotency_key
 * @property string|null $notes
 * @property CarbonInterface $created_at
 */
class CompletionResponse extends Model
{
    use BelongsToAccount, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => ResponseType::class,
            'punctuality' => Punctuality::class,
            'channel' => ResponseChannel::class,
            'response_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Completion responses are append-only.'));
        static::deleting(fn () => throw new LogicException('Completion responses are append-only.'));
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<TaskOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(TaskOccurrence::class, 'occurrence_id');
    }
}
