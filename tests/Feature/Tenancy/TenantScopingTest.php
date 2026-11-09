<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Accounts\Exceptions\CrossTenantWrite;
use App\Domain\Accounts\Exceptions\MissingTenantContext;
use App\Domain\Accounts\TenantContext;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Model-level guarantees of the BelongsToAccount trait.
 */
class TenantScopingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_tenant_data_cannot_be_queried_without_a_tenant_context(): void
    {
        $this->alice();

        $this->expectException(MissingTenantContext::class);

        UserPreference::query()->get();
    }

    public function test_a_tenant_only_sees_its_own_rows(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();

        $bobPreferenceId = $this->withoutTenancy(fn () => UserPreference::where('user_id', $bob->id)->value('id'));

        $this->asTenant($alice, function () use ($alice, $bobPreferenceId) {
            $this->assertSame([$alice->id], UserPreference::pluck('user_id')->all());
            $this->assertNull(UserPreference::find($bobPreferenceId), 'Guessed ULID of another tenant must not resolve.');
            $this->assertSame(1, UserPreference::count());
        });
    }

    public function test_relations_are_scoped_too(): void
    {
        $bob = $this->bob();

        $this->asTenant($this->alice(), function () use ($bob) {
            $this->assertNull($bob->preference()->first());
        });
    }

    public function test_forged_account_id_is_not_mass_assignable(): void
    {
        $preference = new UserPreference(['account_id' => 'forged', 'timezone' => 'UTC']);

        $this->assertNull($preference->account_id);
    }

    public function test_writing_into_another_account_is_rejected(): void
    {
        $bobAccountId = $this->accountOf($this->bob())->id;
        $alice = $this->alice();

        $this->expectException(CrossTenantWrite::class);

        $this->asTenant($alice, function () use ($alice, $bobAccountId) {
            $preference = new UserPreference(['timezone' => 'UTC']);
            $preference->forceFill(['user_id' => $alice->id, 'account_id' => $bobAccountId])->save();
        });
    }

    public function test_account_id_cannot_be_changed_after_creation(): void
    {
        $alice = $this->alice();
        $bobAccountId = $this->accountOf($this->bob())->id;

        $this->expectException(CrossTenantWrite::class);

        $this->asTenant($alice, function () use ($bobAccountId) {
            $preference = UserPreference::sole();
            $preference->forceFill(['account_id' => $bobAccountId])->save();
        });
    }

    public function test_run_as_restores_the_previous_context(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $context = app(TenantContext::class);

        $this->asTenant($alice, function () use ($context, $bob, $alice) {
            $this->asTenant($bob, fn () => $this->assertSame($this->accountOf($bob)->id, $context->accountId()));

            $this->assertSame($this->accountOf($alice)->id, $context->accountId());
        });

        $this->assertFalse($context->has());
    }

    public function test_without_tenancy_is_explicit_and_temporary(): void
    {
        $this->alice();
        $this->bob();

        $this->assertSame(2, $this->withoutTenancy(fn () => UserPreference::count()));

        $this->expectException(MissingTenantContext::class);
        UserPreference::count();
    }
}
