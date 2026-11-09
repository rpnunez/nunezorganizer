<?php

namespace App\Enums;

/**
 * Measured against the occurrence's planned end, never the notification send time.
 */
enum Punctuality: string
{
    case OnTime = 'on_time';
    case WithinGrace = 'within_grace';
    case Late = 'late';
}
