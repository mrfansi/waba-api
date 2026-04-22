<?php

namespace App\Waba\Drivers;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\NormalizedStatusEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;
use App\Waba\Exceptions\DriverException;
use App\Waba\Exceptions\DriverTimeoutException;
use App\Waba\Exceptions\PermanentSendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class QiscusDriver implements MessageProvider
{
    private ?ChannelCredentials $credentials = null;

    /** @var array{request:array<string,mixed>,response:array<string,mixed>}|null */
    private ?array $lastTransaction = null;

    public function name(): string
    {
        return 'qiscus';
    }

    public function bind(ChannelCredentials $credentials): static
    {
        $clone = clone $this;
        $clone->credentials = $credentials;
        $clone->lastTransaction = null;

        return $clone;
    }

    public function probe(): bool
    {
        $creds = $this->requireCredentials();

        try {
            $response = Http::timeout((int) config('waba.providers.qiscus.timeout', 15))
                ->withHeaders($this->authHeaders($creds))
                ->get($this->baseUrl().'/api/v2/app/config');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function send(OutboundMessage $message): SendResult
    {
        $creds = $this->requireCredentials();
        $body = $this->buildSendBody($message);

        $this->lastTransaction = ['request' => $body, 'response' => []];

        try {
            $response = Http::timeout((int) config('waba.providers.qiscus.timeout', 15))
                ->withHeaders($this->authHeaders($creds))
                ->asJson()
                ->post($this->sendUrl($creds), $body);
        } catch (ConnectionException $e) {
            throw new DriverTimeoutException('Qiscus connection error: '.$e->getMessage());
        }

        $this->lastTransaction['response'] = [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];

        if ($response->status() >= 400 && $response->status() < 500) {
            throw new PermanentSendException(
                "Qiscus rejected message: HTTP {$response->status()}",
                ['provider' => 'qiscus', 'upstream_status' => $response->status(), 'body' => $response->json()],
            );
        }

        if (! $response->successful()) {
            throw new DriverException(
                "Qiscus upstream error: HTTP {$response->status()}",
                ['provider' => 'qiscus', 'upstream_status' => $response->status()],
            );
        }

        $providerId = (string) ($response->json('data.id') ?? $response->json('id') ?? '');

        return new SendResult(
            providerMessageId: $providerId,
            status: 'accepted',
            raw: $response->json() ?? [],
            sentAt: now(),
        );
    }

    /**
     * Return the raw request/response pair from the most recent send() call.
     *
     * @return array{request:array<string,mixed>,response:array<string,mixed>}|null
     */
    public function lastTransaction(): ?array
    {
        return $this->lastTransaction;
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $creds = $this->requireCredentials();
        $provided = $headers['x-qiscus-signature'][0] ?? $headers['X-Qiscus-Signature'][0] ?? null;

        if ($provided === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $creds->webhookSecret);

        return hash_equals($expected, (string) $provided);
    }

    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent
    {
        if (($rawPayload['event'] ?? null) !== 'status') {
            return null;
        }

        $data = $rawPayload['data'] ?? [];

        $occurred = isset($data['timestamp'])
            ? Carbon::parse((string) $data['timestamp'])
            : now();

        return new NormalizedStatusEvent(
            providerMessageId: (string) ($data['message_id'] ?? ''),
            status: (string) ($data['status'] ?? 'sent'),
            occurredAt: $occurred,
            errorCode: isset($data['error']['code']) ? (string) $data['error']['code'] : null,
            errorMessage: isset($data['error']['message']) ? (string) $data['error']['message'] : null,
        );
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

    /** @return array<string,string> */
    private function authHeaders(ChannelCredentials $creds): array
    {
        return [
            'Qiscus-App-Id' => (string) $creds->get('app_id'),
            'Qiscus-Secret-Key' => (string) $creds->get('secret_key'),
            'Accept' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('waba.providers.qiscus.base_url'), '/');
    }

    private function sendUrl(ChannelCredentials $creds): string
    {
        $appId = (string) $creds->get('app_id');
        $phoneId = (string) $creds->get('phone_number_id', '');

        return $this->baseUrl()."/{$appId}/api/v1/qiscus/whatsapp/{$phoneId}/send";
    }

    /** @return array<string,mixed> */
    private function buildSendBody(OutboundMessage $message): array
    {
        $base = ['to' => $message->to, 'type' => $message->type];

        return match (true) {
            $message->payload instanceof TextPayload => $base + [
                'text' => ['body' => $message->payload->body, 'preview_url' => $message->payload->previewUrl],
            ],
            $message->payload instanceof MediaPayload => $base + [
                $message->payload->kind => array_filter([
                    'link' => $message->payload->url,
                    'caption' => $message->payload->caption,
                    'filename' => $message->payload->filename,
                ], fn ($v) => $v !== null),
            ],
            $message->payload instanceof TemplatePayload => $base + [
                'template' => [
                    'name' => $message->payload->name,
                    'language' => ['code' => $message->payload->language],
                    'components' => $message->payload->components,
                ],
            ],
            $message->payload instanceof InteractivePayload => $base + [
                'interactive' => array_filter([
                    'type' => $message->payload->kind,
                    'header' => $message->payload->header ? ['type' => 'text', 'text' => $message->payload->header] : null,
                    'body' => ['text' => $message->payload->body],
                    'footer' => $message->payload->footer ? ['text' => $message->payload->footer] : null,
                    'action' => $message->payload->action,
                ], fn ($v) => $v !== null),
            ],
            $message->payload instanceof LocationPayload => $base + [
                'location' => array_filter([
                    'latitude' => $message->payload->latitude,
                    'longitude' => $message->payload->longitude,
                    'name' => $message->payload->name,
                    'address' => $message->payload->address,
                ], fn ($v) => $v !== null),
            ],
            $message->payload instanceof ContactPayload => $base + ['contacts' => $message->payload->contacts],
        };
    }

    private function requireCredentials(): ChannelCredentials
    {
        if ($this->credentials === null) {
            throw new DriverException('QiscusDriver called without bound credentials');
        }

        return $this->credentials;
    }
}
