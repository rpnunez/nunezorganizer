<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only. Write through App\Domain\Audit\Audit::record().
 *
 * Not tenant-scoped via BelongsToAccount on purpose: system events may have no
 * account. Any user-facing audit view must filter by the current tenant explicitly.
 *
 * @property string $id
 * @property string|null $account_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $created_at
 */
class AuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }
}
