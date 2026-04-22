<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class InteractivePayload
{
    /** @param array<string,mixed> $action */
    public function __construct(
        public string $kind,
        public string $body,
        public array $action,
        public ?string $header = null,
        public ?string $footer = null,
    ) {}
}
