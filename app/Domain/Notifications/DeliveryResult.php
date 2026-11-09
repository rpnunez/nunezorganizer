<?php

namespace App\Domain\Notifications;

final readonly class DeliveryResult
{
    public function __construct(
        public bool $sent,
        public ?string $externalMessageId = null,
    ) {}

    public static function sent(string $externalMessageId): self
    {
        return new self(true, $externalMessageId);
    }

    public static function skipped(): self
    {
        return new self(false);
    }
}
