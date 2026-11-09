<?php

namespace Database\Factories;

use App\Domain\Accounts\TenantContext;
use App\Enums\TaskStatus;
use App\Models\AccountMembership;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Owner defaults to the current tenant's user (inside asTenant()), otherwise a new user.
 * account_id is always derived from the owner's personal account.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Organize my desk', 'Clean one shelf', 'Reply to two emails', 'Stretch for 15 minutes', 'Plan tomorrow']),
            'description' => fake()->optional()->sentence(),
            'duration_minutes' => 15,
            'status' => TaskStatus::Open,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Task $task): void {
            // Raw attribute checks: before persistence these may genuinely be unset.
            if ($task->getAttribute('owner_user_id') === null) {
                $owner = app(TenantContext::class)->user() ?? User::factory()->create();
                $task->owner_user_id = $owner->id;
            }

            if ($task->getAttribute('account_id') === null) {
                $task->account_id = (string) AccountMembership::query()
                    ->where('user_id', $task->owner_user_id)
                    ->value('account_id');
            }
        });
    }

    public function ownedBy(User $user): static
    {
        return $this->state(['owner_user_id' => $user->id]);
    }

    public function completed(): static
    {
        return $this->state(['status' => TaskStatus::Completed, 'completed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => TaskStatus::Cancelled, 'cancelled_at' => now()]);
    }
}
