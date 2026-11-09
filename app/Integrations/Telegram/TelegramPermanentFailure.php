<?php

namespace App\Integrations\Telegram;

use RuntimeException;

class TelegramPermanentFailure extends RuntimeException
{
    public function __construct(public readonly bool $blocked)
    {
        parent::__construct('Telegram rejected delivery.');
    }
}
