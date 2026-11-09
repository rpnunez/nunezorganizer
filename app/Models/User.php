<?php

namespace App\Models;

use App\Domain\Accounts\Actions\CreatePersonalAccount;
use App\Enums\MembershipStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $approved_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Every new user gets a personal account, owner membership and default preferences.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            app(CreatePersonalAccount::class)->handle($user);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
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
     * Accounts the user is an active member of.
     *
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_memberships')
            ->wherePivot('status', MembershipStatus::Active->value)
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    /**
     * The user's preferences. Tenant-scoped: read it inside a tenant context.
     *
     * @return HasOne<UserPreference, $this>
     */
    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * Whether the user may use the app (always true outside "approval" registration mode).
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
