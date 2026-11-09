<?php

namespace App\Domain\Accounts\Jobs;

use App\Domain\Accounts\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

/**
 * Job middleware: `public function middleware(): array { return [new WithTenantContext]; }`
 *
 * Re-verifies, at execution time, that the job's user still exists and still has an
 * active membership in the job's account, then runs the job inside that tenant.
 * The previous context (none on a queue worker; the request's tenant for sync
 * dispatch) is restored afterwards, so nothing leaks between jobs.
 * If verification fails, the job is deleted without running.
 */
class WithTenantContext
{
    public function handle(TenantAware $job, Closure $next): mixed
    {
        $tenant = app(TenantContext::class);
        $user = User::find($job->tenantUserId());

        try {
            if ($user === null) {
                throw new AuthorizationException('Job user no longer exists.');
            }

            return $tenant->runAs($user, fn () => $next($job), $job->tenantAccountId());
        } catch (AuthorizationException $e) {
            Log::warning('Tenant-aware job dropped: tenant verification failed.', [
                'job' => $job::class,
                'user_id' => $job->tenantUserId(),
                'reason' => $e->getMessage(),
            ]);

            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return null;
        }
    }
}
