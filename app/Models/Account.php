<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A logical tenant. In the MVP every user owns exactly one personal account.
 *
 * @property string $id
 * @property string $name
 * @property AccountType $type
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Account extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
        ];
    }

    /**
     * @return HasMany<AccountMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(AccountMembership::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasActiveMember(User $user): bool
    {
        return $this->memberships()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }
}
