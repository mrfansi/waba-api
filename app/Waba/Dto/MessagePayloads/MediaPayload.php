<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class MediaPayload
{
    public function __construct(
        public string $kind,
        public string $url,
        public ?string $caption = null,
        public ?string $filename = null,
    ) {}
}
