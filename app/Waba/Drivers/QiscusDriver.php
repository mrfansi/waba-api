<?php

namespace App\Waba\Drivers;

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
use App\Waba\Exceptions\DriverException;
use Illuminate\Support\Facades\Http;
use Throwable;

class QiscusDriver implements MessageProvider
{
    private ?ChannelCredentials $credentials = null;

    public function name(): string
    {
        return 'qiscus';
    }

    public function bind(ChannelCredentials $credentials): static
    {
        $clone = clone $this;
        $clone->credentials = $credentials;

        return $clone;
    }

    public function probe(): bool
    {
        $creds = $this->requireCredentials();

        try {
            $response = Http::timeout((int) config('waba.providers.qiscus.timeout', 15))
                ->withHeaders([
                    'Qiscus-App-Id' => (string) $creds->get('app_id'),
                    'Qiscus-Secret-Key' => (string) $creds->get('secret_key'),
                    'Accept' => 'application/json',
                ])
                ->get(rtrim((string) config('waba.providers.qiscus.base_url'), '/').'/api/v2/app/config');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function send(OutboundMessage $message): SendResult
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function listTemplates(): array
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function submitTemplate(TemplateDefinition $def): TemplateSyncResult
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function deleteTemplate(string $providerTemplateId): void
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function uploadMedia(MediaUpload $upload): MediaReference
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function downloadMedia(string $providerMediaId): MediaReference
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    private function requireCredentials(): ChannelCredentials
    {
        if ($this->credentials === null) {
            throw new DriverException('QiscusDriver called without bound credentials');
        }

        return $this->credentials;
    }
}
