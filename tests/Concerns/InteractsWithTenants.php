<?php

namespace Tests\Concerns;

use App\Domain\Accounts\TenantContext;
use App\Models\Account;
use App\Models\User;
use Closure;

/**
 * Alice/Bob fixtures for tenant-isolation tests.
 *
 * Every feature touching tenant data must prove that Alice cannot read, enumerate
 * or mutate Bob's data. Prefer asserting 404 for foreign resources (ADR 0002).
 */
trait InteractsWithTenants
{
    private ?User $aliceUser = null;

    private ?User $bobUser = null;

    protected function alice(): User
    {
        return $this->aliceUser ??= User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.test']);
    }

    protected function bob(): User
    {
        return $this->bobUser ??= User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.test']);
    }

    protected function accountOf(User $user): Account
    {
        return $user->accounts()->firstOrFail();
    }

    /**
     * Run code inside a user's tenant context (for direct, non-HTTP domain calls).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function asTenant(User $user, Closure $callback): mixed
    {
        return app(TenantContext::class)->runAs($user, $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withoutTenancy(Closure $callback): mixed
    {
        return app(TenantContext::class)->withoutTenancy($callback);
    }
}
