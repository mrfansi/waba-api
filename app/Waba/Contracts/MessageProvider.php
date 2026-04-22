<?php

namespace App\Waba\Contracts;

use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\NormalizedStatusEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;

interface MessageProvider
{
    public function name(): string;

    public function bind(ChannelCredentials $credentials): static;

    public function probe(): bool;

    // P2
    public function send(OutboundMessage $message): SendResult;

    // P3
    /** @param array<string,string|array<int,string>> $headers */
    public function verifyWebhookSignature(string $payload, array $headers): bool;

    /** @param array<string,mixed> $rawPayload */
    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent;

    /** @param array<string,mixed> $rawPayload */
    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent;

    // P4
    /** @return array<int,TemplateDefinition> */
    public function listTemplates(): array;

    public function submitTemplate(TemplateDefinition $def): TemplateSyncResult;

    public function deleteTemplate(string $providerTemplateId): void;

    // P5
    public function uploadMedia(MediaUpload $upload): MediaReference;

    public function downloadMedia(string $providerMediaId): MediaReference;
}
