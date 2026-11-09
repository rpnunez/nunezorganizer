<?php

namespace App\Livewire\Concerns;

use App\Domain\Accounts\TenantContext;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\Auth;

/**
 * Every app page resolves the tenant from the authenticated user on each Livewire
 * request (in addition to the EnsureTenant HTTP middleware), and exposes the user's
 * timezone for display. Never from client state.
 */
trait InTenant
{
    public function bootInTenant(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $tenant = app(TenantContext::class);

        if (! $tenant->has() || $tenant->user()?->id !== $user->id) {
            $tenant->setForUser($user);
        }
    }

    protected function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function userTimezone(): string
    {
        return (string) (UserPreference::query()->where('user_id', $this->currentUser()->id)->value('timezone') ?? 'UTC');
    }
}
