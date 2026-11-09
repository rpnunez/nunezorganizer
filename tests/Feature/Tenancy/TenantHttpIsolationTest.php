<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Accounts\TenantContext;
use App\Enums\MembershipStatus;
use App\Models\AccountMembership;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * T01 / T02: the tenant comes from authentication; forged identifiers grant nothing.
 * Uses throwaway routes so the guarantees are tested independently of real features.
 */
class TenantHttpIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->group(function () {
            Route::match(['get', 'post'], '/__test/tenant', function (Request $request) {
                return response()->json([
                    'account_id' => app(TenantContext::class)->accountId(),
                    'visible_user_ids' => UserPreference::pluck('user_id')->all(),
                ]);
            });

            Route::get('/__test/preferences/{preference}', fn (UserPreference $preference) => response()->json(['id' => $preference->id]));
        });
    }

    public function test_tenant_is_resolved_from_the_authenticated_user(): void
    {
        $alice = $this->alice();
        $this->bob();

        $this->actingAs($alice)->getJson('/__test/tenant')
            ->assertOk()
            ->assertExactJson([
                'account_id' => $this->accountOf($alice)->id,
                'visible_user_ids' => [$alice->id],
            ]);
    }

    public function test_forged_account_id_in_query_or_body_is_ignored(): void
    {
        $alice = $this->alice();
        $bobAccountId = $this->accountOf($this->bob())->id;

        $this->actingAs($alice)->getJson('/__test/tenant?account_id='.$bobAccountId)
            ->assertJsonPath('account_id', $this->accountOf($alice)->id)
            ->assertJsonPath('visible_user_ids', [$alice->id]);

        $this->actingAs($alice)->postJson('/__test/tenant', ['account_id' => $bobAccountId])
            ->assertJsonPath('account_id', $this->accountOf($alice)->id);
    }

    public function test_bound_foreign_resource_returns_404(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();

        [$alicePrefId, $bobPrefId] = $this->withoutTenancy(fn () => [
            UserPreference::where('user_id', $alice->id)->value('id'),
            UserPreference::where('user_id', $bob->id)->value('id'),
        ]);

        $this->actingAs($alice)->getJson("/__test/preferences/{$alicePrefId}")->assertOk();
        $this->actingAs($alice)->getJson("/__test/preferences/{$bobPrefId}")->assertNotFound();
    }

    public function test_user_without_an_active_membership_is_forbidden(): void
    {
        $alice = $this->alice();
        AccountMembership::where('user_id', $alice->id)->update(['status' => MembershipStatus::Suspended]);

        $this->actingAs($alice)->getJson('/__test/tenant')->assertForbidden();
    }

    public function test_tenant_context_does_not_outlive_the_request(): void
    {
        $this->actingAs($this->alice())->getJson('/__test/tenant')->assertOk();

        $this->assertFalse(app(TenantContext::class)->has());
    }
}
