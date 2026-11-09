<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\TenantContext;
use App\Domain\Audit\Audit;
use App\Enums\AccountType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;

/**
 * Provision a new user's personal account, owner membership and default preferences.
 * Runs automatically when a User is created (see User::booted()).
 */
class CreatePersonalAccount
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(User $user): Account
    {
        // System provisioning: writes target the *new* account explicitly, independent of
        // whichever tenant (if any) is active in the current request.
        return $this->tenant->withoutTenancy(fn (): Account => DB::transaction(function () use ($user): Account {
            $account = Account::create([
                'name' => $user->name,
                'type' => AccountType::Personal,
                'created_by' => $user->id,
            ]);

            AccountMembership::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
            ]);

            $preference = new UserPreference([
                'timezone' => (string) config('nunezorganizer.default_timezone', 'UTC'),
                'grace_minutes' => UserPreference::DEFAULT_GRACE_MINUTES,
                'unanswered_after_minutes' => UserPreference::DEFAULT_UNANSWERED_AFTER_MINUTES,
            ]);
            $preference->forceFill(['user_id' => $user->id, 'account_id' => $account->id])->save();

            Audit::record('account.created', $account, actor: $user, accountId: $account->id);

            return $account;
        }));
    }
}
