<?php

namespace App\Domain\Accounts;

use App\Domain\Accounts\Exceptions\MissingTenantContext;
use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The verified tenant for the current request or job.
 *
 * Registered as a *scoped* singleton, so Laravel resets it between HTTP requests
 * and between queued jobs. It is only ever populated from an authenticated user's
 * active membership, never from request input.
 */
class TenantContext
{
    private ?Account $account = null;

    private ?User $user = null;

    private int $bypassDepth = 0;

    /**
     * Resolve the user's active (personal) account and make it current.
     *
     * @throws AuthorizationException when the user has no active membership
     */
    public function setForUser(User $user, ?string $accountId = null): Account
    {
        $membership = AccountMembership::query()
            ->with('account')
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->whereHas('account', fn ($q) => $q->where('type', AccountType::Personal))
            ->orderBy('created_at')
            ->first();

        if ($membership === null || $membership->account === null) {
            throw new AuthorizationException('No active account membership.');
        }

        $this->account = $membership->account;
        $this->user = $user;

        return $this->account;
    }

    public function clear(): void
    {
        $this->account = null;
        $this->user = null;
    }

    public function has(): bool
    {
        return $this->account !== null;
    }

    public function account(): Account
    {
        return $this->account ?? throw new MissingTenantContext;
    }

    public function accountId(): string
    {
        return $this->account()->id;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * Run a callback as a specific user's tenant, restoring the previous context afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(User $user, Closure $callback, ?string $accountId = null): mixed
    {
        [$previousAccount, $previousUser] = [$this->account, $this->user];

        try {
            $this->setForUser($user, $accountId);

            return $callback();
        } finally {
            [$this->account, $this->user] = [$previousAccount, $previousUser];
        }
    }

    /**
     * Explicitly run system-level code (console commands, maintenance) without tenant scoping.
     * Use sparingly; never inside request handling for end users.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutTenancy(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
