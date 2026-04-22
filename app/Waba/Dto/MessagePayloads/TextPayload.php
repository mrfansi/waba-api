<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class TextPayload
{
    public function __construct(public string $body, public bool $previewUrl = false) {}
}
