<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The logical unit of work. Exists independently of any scheduled occurrence.
 *
 * @property string $id
 * @property string $account_id
 * @property int $owner_user_id
 * @property string $title
 * @property string|null $description
 * @property int $duration_minutes
 * @property TaskStatus $status
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Task extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    /**
     * Ownership and lifecycle fields are set by domain actions, never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'description', 'duration_minutes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'duration_minutes' => 15,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'duration_minutes' => 'integer',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<TaskOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(TaskOccurrence::class)->orderBy('scheduled_start_at');
    }

    /**
     * @return HasMany<CompletionResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(CompletionResponse::class)->orderBy('response_at');
    }

    public function isOpen(): bool
    {
        return $this->status === TaskStatus::Open;
    }
}
