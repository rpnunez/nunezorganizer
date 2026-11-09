<?php

namespace App\Enums;

/**
 * State of one scheduled attempt. Independent of the task's logical status.
 */
enum OccurrenceState: string
{
    case Scheduled = 'scheduled';
    case AwaitingResponse = 'awaiting_response';
    case Finished = 'finished';
    case Missed = 'missed';
    case Unanswered = 'unanswered';
    case Cancelled = 'cancelled';

    /**
     * Terminal states cannot be rescheduled or answered again.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Finished, self::Cancelled], true);
    }
}
