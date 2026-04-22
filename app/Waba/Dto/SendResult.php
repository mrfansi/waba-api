<?php

namespace App\Waba\Dto;

final readonly class SendResult
{
    /** @param array<string,mixed> $raw */
    public function __construct(public string $providerMessageId, public string $status, public array $raw = []) {}
}
