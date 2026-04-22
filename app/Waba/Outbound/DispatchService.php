<?php

namespace App\Waba\Outbound;

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\Message;
use App\Models\MessageStatusEvent;
use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\IdempotencyMismatchException;
use App\Waba\Exceptions\PermanentSendException;
use App\Waba\Facades\Waba;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchService
{
    public function __construct(private IdempotencyStore $idempotency) {}

    public function dispatch(Channel $channel, ChannelApiKey $apiKey, OutboundMessage $msg, string $mode = 'queue'): Message
    {
        $requestHash = $this->hashMessage($msg);

        if ($msg->idempotencyKey !== null) {
            $hit = $this->idempotency->find($apiKey->id, $msg->idempotencyKey);
            if ($hit !== null) {
                if (! hash_equals($hit['request_hash'], $requestHash)) {
                    throw new IdempotencyMismatchException($msg->idempotencyKey);
                }

                $existing = Message::findOrFail($hit['message_id']);
                $existing->wasIdempotentReplay = true;

                return $existing;
            }
        }

        $message = $this->createPending($channel, $apiKey, $msg, $requestHash);

        if ($mode === 'sync') {
            return $this->attemptSend($message);
        }

        SendMessageJob::dispatch($message->id)
            ->onConnection(config('waba.outbound.queue_connection'))
            ->onQueue(config('waba.outbound.queue_name'));

        return $message;
    }

    public function attemptSend(Message $m): Message
    {
        $m->forceFill(['status' => 'sending', 'attempts' => $m->attempts + 1])->save();

        $outbound = $this->dtoFromRow($m);
        $provider = Waba::channel($m->channel->name);

        try {
            $result = $provider->send($outbound);
        } catch (PermanentSendException $e) {
            $this->recordFailure($m, $provider, $e);
            throw $e;
        } catch (Throwable $e) {
            $tx = method_exists($provider, 'lastTransaction') ? $provider->lastTransaction() : null;
            $m->forceFill([
                'request_payload' => $tx['request'] ?? null,
                'response_payload' => $tx['response'] ?? null,
            ])->save();
            throw $e;
        }

        $tx = method_exists($provider, 'lastTransaction') ? $provider->lastTransaction() : null;
        $m->forceFill([
            'status' => 'sent',
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => $result->sentAt ?? now(),
            'request_payload' => $tx['request'] ?? null,
            'response_payload' => $tx['response'] ?? null,
        ])->save();

        MessageStatusEvent::create([
            'message_id' => $m->id,
            'status' => 'sent',
            'occurred_at' => $result->sentAt ?? now(),
            'raw_payload' => $result->raw,
        ]);

        return $m->fresh();
    }

    private function createPending(Channel $channel, ChannelApiKey $apiKey, OutboundMessage $msg, string $hash): Message
    {
        try {
            return DB::transaction(function () use ($channel, $apiKey, $msg, $hash) {
                $row = Message::create([
                    'channel_id' => $channel->id,
                    'direction' => 'outbound',
                    'to_number' => $msg->to,
                    'from_number' => $channel->phone_number ?? null,
                    'type' => $msg->type,
                    'payload' => $this->payloadToArray($msg),
                    'status' => 'pending',
                    'idempotency_key' => $msg->idempotencyKey,
                    'api_key_id' => $apiKey->id,
                    'attempts' => 0,
                ]);

                if ($msg->idempotencyKey !== null) {
                    $this->idempotency->remember($apiKey->id, $msg->idempotencyKey, $row->id, $hash);
                }

                return $row;
            });
        } catch (QueryException $e) {
            if ($msg->idempotencyKey === null) {
                throw $e;
            }
            $existing = Message::where('api_key_id', $apiKey->id)
                ->where('idempotency_key', $msg->idempotencyKey)
                ->firstOrFail();
            $this->idempotency->remember($apiKey->id, $msg->idempotencyKey, $existing->id, $hash);

            return $existing;
        }
    }

    private function recordFailure(Message $m, mixed $provider, PermanentSendException $e): void
    {
        $tx = method_exists($provider, 'lastTransaction') ? $provider->lastTransaction() : null;
        $m->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'error_code' => $e->errorCode(),
            'error_message' => $e->getMessage(),
            'request_payload' => $tx['request'] ?? null,
            'response_payload' => $tx['response'] ?? null,
        ])->save();

        MessageStatusEvent::create([
            'message_id' => $m->id,
            'status' => 'failed',
            'occurred_at' => now(),
            'raw_payload' => $e->details(),
        ]);
    }

    /** @return array<string,mixed> */
    private function payloadToArray(OutboundMessage $msg): array
    {
        return get_object_vars($msg->payload);
    }

    private function dtoFromRow(Message $m): OutboundMessage
    {
        $p = $m->payload;
        $payload = match ($m->type) {
            'text' => new TextPayload($p['body'] ?? '', (bool) ($p['previewUrl'] ?? false)),
            'media' => new MediaPayload($p['kind'], $p['url'], $p['caption'] ?? null, $p['filename'] ?? null),
            'template' => new TemplatePayload($p['name'], $p['language'], $p['components'] ?? []),
            'interactive' => new InteractivePayload($p['kind'], $p['body'], $p['action'] ?? [], $p['header'] ?? null, $p['footer'] ?? null),
            'location' => new LocationPayload((float) $p['latitude'], (float) $p['longitude'], $p['name'] ?? null, $p['address'] ?? null),
            'contact' => new ContactPayload($p['contacts'] ?? []),
        };

        return new OutboundMessage($m->to_number, $m->type, $payload);
    }

    private function hashMessage(OutboundMessage $m): string
    {
        return hash('sha256', json_encode([
            'to' => $m->to,
            'type' => $m->type,
            'payload' => get_object_vars($m->payload),
        ]));
    }
}
