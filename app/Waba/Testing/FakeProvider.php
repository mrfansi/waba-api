<?php

namespace App\Waba\Testing;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\NormalizedStatusEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;
use PHPUnit\Framework\Assert;

class FakeProvider implements MessageProvider
{
    /** @var array<int,array{method:string,args:array<int,mixed>}> */
    public array $calls = [];

    public bool $probeResult = true;

    public ?NormalizedStatusEvent $normalizedStatusResult = null;

    public ?\Throwable $throwOnSend = null;

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

        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }

        return new SendResult('fake-id', 'accepted', sentAt: now());
    }

    /** @return array{request:array<string,mixed>,response:array<string,mixed>} */
    public function lastTransaction(): array
    {
        return ['request' => [], 'response' => []];
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

    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent
    {
        $this->record(__FUNCTION__, [$rawPayload]);

        return $this->normalizedStatusResult;
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

    public function assertSent(?callable $matcher = null): void
    {
        $sends = array_filter($this->calls, fn ($c) => $c['method'] === 'send');
        Assert::assertNotEmpty($sends, 'No messages sent');
        if ($matcher !== null) {
            $matched = array_filter($sends, fn ($c) => $matcher($c['args'][0]));
            Assert::assertNotEmpty($matched, 'No sent message matched');
        }
    }

    public function assertNothingSent(): void
    {
        $sends = array_filter($this->calls, fn ($c) => $c['method'] === 'send');
        Assert::assertEmpty($sends);
    }

    public function assertSentCount(int $count): void
    {
        $sends = array_filter($this->calls, fn ($c) => $c['method'] === 'send');
        Assert::assertCount($count, $sends);
    }

    /** @param array<int,mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
