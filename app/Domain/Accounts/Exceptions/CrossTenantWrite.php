<?php

namespace App\Domain\Accounts\Exceptions;

use LogicException;

/**
 * Thrown when code attempts to write a row into an account other than the current tenant,
 * or to move an existing row to a different account.
 */
class CrossTenantWrite extends LogicException
{
    public function __construct(string $message = 'Attempted to write tenant-owned data across accounts.')
    {
        parent::__construct($message);
    }
}
