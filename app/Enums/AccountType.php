<?php

namespace App\Enums;

enum AccountType: string
{
    /** One private account per user. Shared accounts are not supported in the MVP. */
    case Personal = 'personal';
}
