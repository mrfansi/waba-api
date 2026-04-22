<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class TemplatePayload
{
    /** @param array<int,array{type:string,parameters:array<int,mixed>}> $components */
    public function __construct(
        public string $name,
        public string $language,
        public array $components = [],
    ) {}
}
