<?php

namespace App\Domain\Accounts\Exceptions;

use LogicException;

/**
 * Thrown when tenant-owned data is accessed without a verified tenant context.
 * Tenancy fails closed: no context means no data, never "all data".
 */
class MissingTenantContext extends LogicException
{
    public function __construct(string $message = 'Tenant-owned data was accessed without a tenant context.')
    {
        parent::__construct($message);
    }
}
