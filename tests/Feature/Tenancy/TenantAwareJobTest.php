<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Accounts\Jobs\TenantAware;
use App\Domain\Accounts\Jobs\WithTenantContext;
use App\Domain\Accounts\TenantContext;
use App\Enums\MembershipStatus;
use App\Models\AccountMembership;
use App\Models\UserPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TenantAwareJobTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordTenantJob::$observations = [];
    }

    public function test_job_runs_inside_its_own_users_tenant(): void
    {
        $bob = $this->bob();
        $this->alice();

        RecordTenantJob::dispatch($bob->id, $this->accountOf($bob)->id);

        $this->assertSame([[
            'account_id' => $this->accountOf($bob)->id,
            'visible_user_ids' => [$bob->id],
        ]], RecordTenantJob::$observations);
    }

    public function test_job_context_does_not_leak_into_or_out_of_the_dispatcher(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $context = app(TenantContext::class);

        $this->asTenant($alice, function () use ($bob, $alice, $context) {
            RecordTenantJob::dispatch($bob->id, $this->accountOf($bob)->id);

            // Sync dispatch inside Alice's request: Alice's context is restored afterwards.
            $this->assertSame($this->accountOf($alice)->id, $context->accountId());
        });

        $this->assertSame($this->accountOf($bob)->id, RecordTenantJob::$observations[0]['account_id']);
        $this->assertFalse($context->has());
    }

    public function test_consecutive_jobs_for_different_users_are_isolated(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();

        RecordTenantJob::dispatch($alice->id, $this->accountOf($alice)->id);
        RecordTenantJob::dispatch($bob->id, $this->accountOf($bob)->id);

        $this->assertSame([$alice->id], RecordTenantJob::$observations[0]['visible_user_ids']);
        $this->assertSame([$bob->id], RecordTenantJob::$observations[1]['visible_user_ids']);
    }

    public function test_job_with_mismatched_account_does_not_run(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();

        // A job claiming to act for Alice inside Bob's account must be dropped.
        RecordTenantJob::dispatch($alice->id, $this->accountOf($bob)->id);

        $this->assertSame([], RecordTenantJob::$observations);
    }

    public function test_job_for_suspended_membership_does_not_run(): void
    {
        $alice = $this->alice();
        $accountId = $this->accountOf($alice)->id;
        AccountMembership::where('user_id', $alice->id)->update(['status' => MembershipStatus::Suspended]);

        RecordTenantJob::dispatch($alice->id, $accountId);

        $this->assertSame([], RecordTenantJob::$observations);
    }

    public function test_job_for_deleted_user_does_not_run(): void
    {
        $alice = $this->alice();
        $accountId = $this->accountOf($alice)->id;
        $userId = $alice->id;
        $alice->delete();

        RecordTenantJob::dispatch($userId, $accountId);

        $this->assertSame([], RecordTenantJob::$observations);
    }
}

/**
 * Test double: records what tenant data it can see.
 */
class RecordTenantJob implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array{account_id: string, visible_user_ids: list<int>}> */
    public static array $observations = [];

    public function __construct(public int $userId, public string $accountId) {}

    public function tenantUserId(): int
    {
        return $this->userId;
    }

    public function tenantAccountId(): string
    {
        return $this->accountId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithTenantContext];
    }

    public function handle(TenantContext $tenant): void
    {
        self::$observations[] = [
            'account_id' => $tenant->accountId(),
            'visible_user_ids' => UserPreference::pluck('user_id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
