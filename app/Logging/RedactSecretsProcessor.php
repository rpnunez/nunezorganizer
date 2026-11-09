<?php

namespace App\Logging;

use App\Domain\Audit\Redactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks bot tokens, bearer credentials and sensitive context keys before log records are written.
 */
class RedactSecretsProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: Redactor::redactString($record->message),
            context: Redactor::redact($record->context),
            extra: Redactor::redact($record->extra),
        );
    }
}
