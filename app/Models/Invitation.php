<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use, expiring registration invitation. Only a SHA-256 hash of the
 * token is stored; the plain token exists only in the link given to the invitee.
 *
 * @property string $id
 * @property string $email
 * @property string $token_hash
 * @property int|null $invited_by
 * @property int|null $accepted_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invitation extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = ['email', 'token_hash', 'invited_by', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Create an invitation and return it together with its plain token.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $email, ?User $inviter = null, ?int $ttlDays = null): array
    {
        $token = Str::random(48);
        $days = $ttlDays ?? (int) config('nunezorganizer.registration.invitation_ttl_days', 7);

        $invitation = self::create([
            'email' => self::normalizeEmail($email),
            'token_hash' => self::hashToken($token),
            'invited_by' => $inviter?->id,
            'expires_at' => now()->addDays(max(1, $days)),
        ]);

        return [$invitation, $token];
    }

    /**
     * Find an unexpired, unaccepted invitation by its plain token.
     */
    public static function findUsable(mixed $token, bool $lockForUpdate = false): ?self
    {
        if (! is_string($token) || $token === '' || strlen($token) > 255) {
            return null;
        }

        $query = self::query()
            ->where('token_hash', self::hashToken($token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function matchesEmail(string $email): bool
    {
        return hash_equals($this->email, self::normalizeEmail($email));
    }

    public function markAccepted(User $user): void
    {
        $this->forceFill([
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ])->save();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
