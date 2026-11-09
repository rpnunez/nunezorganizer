<?php

namespace Tests\Feature\Tenancy;

use App\Enums\AccountType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AccountMembership;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PersonalAccountProvisioningTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_new_user_gets_exactly_one_personal_account_owner_membership_and_preferences(): void
    {
        $alice = $this->alice();

        $memberships = AccountMembership::where('user_id', $alice->id)->get();
        $this->assertCount(1, $memberships);

        $membership = $memberships->sole();
        $this->assertSame(MembershipRole::Owner, $membership->role);
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame(AccountType::Personal, $membership->account?->type);

        $preference = $this->withoutTenancy(fn () => UserPreference::where('user_id', $alice->id)->sole());
        $this->assertSame($membership->account_id, $preference->account_id);
        $this->assertSame('UTC', $preference->timezone);
        $this->assertSame(15, $preference->grace_minutes);

        $this->assertTrue(AuditEvent::where('action', 'account.created')->where('account_id', $membership->account_id)->exists());
    }

    public function test_each_user_gets_a_distinct_account(): void
    {
        $this->assertNotSame($this->accountOf($this->alice())->id, $this->accountOf($this->bob())->id);
    }

    public function test_provisioning_is_not_affected_by_an_active_tenant(): void
    {
        $alice = $this->alice();

        // Creating a user while Alice's tenant is active must not attach Bob's rows to Alice.
        $bob = $this->asTenant($alice, fn () => $this->bob());

        $bobPreference = $this->withoutTenancy(fn () => UserPreference::where('user_id', $bob->id)->sole());
        $this->assertSame($this->accountOf($bob)->id, $bobPreference->account_id);
    }

    public function test_registration_through_invitation_provisions_the_account(): void
    {
        [, $token] = Invitation::issue('carol@example.test');

        $this->post(route('register.store'), [
            'name' => 'Carol',
            'email' => 'carol@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation' => $token,
        ])->assertSessionHasNoErrors();

        $carol = User::where('email', 'carol@example.test')->sole();
        $this->assertSame(1, $carol->accounts()->count());
    }
}
