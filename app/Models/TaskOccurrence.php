<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use App\Domain\Accounts\Exceptions\CrossTenantWrite;
use App\Enums\OccurrenceState;
use Carbon\CarbonInterface;
use Database\Factories\TaskOccurrenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One scheduled attempt at a task. Times are UTC instants; `timezone` is the
 * IANA zone the attempt was planned in.
 *
 * @property string $id
 * @property string $account_id
 * @property string $task_id
 * @property CarbonInterface $scheduled_start_at
 * @property CarbonInterface $scheduled_end_at
 * @property string $timezone
 * @property OccurrenceState $state
 * @property CarbonInterface|null $closed_at
 * @property int $version
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Task|null $task
 */
class TaskOccurrence extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<TaskOccurrenceFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * task_id, account_id, state and version are set by domain actions only.
     *
     * @var list<string>
     */
    protected $fillable = ['scheduled_start_at', 'scheduled_end_at', 'timezone'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'scheduled',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'closed_at' => 'datetime',
            'state' => OccurrenceState::class,
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Defence in depth: an occurrence must live in its task's account.
        static::creating(function (TaskOccurrence $occurrence): void {
            $taskAccountId = Task::withoutGlobalScopes()->whereKey($occurrence->task_id)->value('account_id');

            if ($taskAccountId === null || $taskAccountId !== $occurrence->account_id) {
                throw new CrossTenantWrite('Occurrence account must match its task account.');
            }
        });
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return HasMany<CompletionResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(CompletionResponse::class, 'occurrence_id');
    }
}
