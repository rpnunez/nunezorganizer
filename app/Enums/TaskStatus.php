<?php

namespace App\Enums;

/**
 * Logical task state. Only an explicit completion sets Completed and only an
 * explicit user action sets Cancelled; the passage of time never changes it.
 */
enum TaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this !== self::Open;
    }
}
