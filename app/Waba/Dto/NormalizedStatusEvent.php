<?php

namespace App\Waba\Dto;

final readonly class NormalizedStatusEvent
{
    public function __construct(
        public string $providerMessageId,
        public string $status,
        public \DateTimeInterface $occurredAt,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
