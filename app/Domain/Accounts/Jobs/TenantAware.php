<?php

namespace App\Domain\Accounts\Jobs;

/**
 * Implemented by queued jobs that act on one user's tenant data.
 * Jobs carry IDs only; the tenant is re-resolved and re-verified when the job runs.
 */
interface TenantAware
{
    public function tenantUserId(): int;

    public function tenantAccountId(): string;
}
