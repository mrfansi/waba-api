<?php

namespace App\Waba\Dto;

final readonly class NormalizedInboundEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(public string $type, public array $payload) {}
}
