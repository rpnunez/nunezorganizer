<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Logging "tap": attach with `'tap' => [RedactSecrets::class]` on a channel.
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactSecretsProcessor);
    }
}
