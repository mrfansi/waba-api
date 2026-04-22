<?php

namespace App\Waba\Dto;

use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;

final readonly class OutboundMessage
{
    public function __construct(
        public string $to,
        public string $type,
        public TextPayload|MediaPayload|TemplatePayload|InteractivePayload|LocationPayload|ContactPayload $payload,
        public ?string $idempotencyKey = null,
        public ?string $clientReference = null,
    ) {}
}
