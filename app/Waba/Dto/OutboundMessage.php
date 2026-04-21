<?php

namespace App\Waba\Dto;

final readonly class OutboundMessage
{
    /** @param array<string,mixed> $payload */
    public function __construct(public string $to, public string $type, public array $payload = []) {}
}
