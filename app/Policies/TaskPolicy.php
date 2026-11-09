<?php

namespace App\Policies;

use App\Domain\Accounts\TenantContext;
use App\Models\Task;
use App\Models\User;

/**
 * Owner-within-tenant. Route-model binding is already tenant-scoped (foreign IDs 404),
 * this is the second check: same account AND the task's owner.
 */
class TaskPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has();
    }

    public function create(User $user): bool
    {
        return $this->tenant->has();
    }

    public function view(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    public function cancel(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    private function owns(User $user, Task $task): bool
    {
        return $this->tenant->has()
            && $task->account_id === $this->tenant->accountId()
            && $task->owner_user_id === $user->id;
    }
}
