<?php

namespace App\Integrations\Telegram;

use RuntimeException;

class TelegramRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Telegram rate limit exceeded.');
    }
}
