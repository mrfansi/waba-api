<?php

namespace App\Waba\Testing;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;

class FakeProvider implements MessageProvider
{
    /** @var array<int,array{method:string,args:array<int,mixed>}> */
    public array $calls = [];

    public bool $probeResult = true;

    public function name(): string
    {
        return 'fake';
    }

    public function bind(ChannelCredentials $credentials): static
    {
        $this->record(__FUNCTION__, [$credentials]);

        return $this;
    }

    public function probe(): bool
    {
        $this->record(__FUNCTION__, []);

        return $this->probeResult;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $this->record(__FUNCTION__, [$message]);

        return new SendResult('fake-id', 'queued');
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $this->record(__FUNCTION__, [$payload, $headers]);

        return true;
    }

    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent
    {
        $this->record(__FUNCTION__, [$rawPayload]);

        return new NormalizedInboundEvent('message', $rawPayload);
    }

    public function listTemplates(): array
    {
        return [];
    }

    public function submitTemplate(TemplateDefinition $def): TemplateSyncResult
    {
        return new TemplateSyncResult('fake-tpl', 'submitted');
    }

    public function deleteTemplate(string $providerTemplateId): void {}

    public function uploadMedia(MediaUpload $upload): MediaReference
    {
        return new MediaReference('fake-media', $upload->mime);
    }

    public function downloadMedia(string $providerMediaId): MediaReference
    {
        return new MediaReference($providerMediaId, 'application/octet-stream');
    }

    /** @param array<int,mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
