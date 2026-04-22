<?php

namespace App\Waba\Outbound;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageStatusEvent;
use App\Waba\Dto\NormalizedStatusEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatusRecorder
{
    private const ORDER = ['pending' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    /** @param array<string,mixed> $rawPayload */
    public function record(Channel $channel, NormalizedStatusEvent $event, array $rawPayload): void
    {
        DB::transaction(function () use ($channel, $event, $rawPayload) {
            $message = Message::query()
                ->where('channel_id', $channel->id)
                ->where('provider_message_id', $event->providerMessageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                Log::warning('waba.status.unknown_message', [
                    'channel_id' => $channel->id,
                    'provider_message_id' => $event->providerMessageId,
                ]);

                return;
            }

            MessageStatusEvent::create([
                'message_id' => $message->id,
                'status' => $event->status,
                'occurred_at' => $event->occurredAt,
                'raw_payload' => $rawPayload,
            ]);

            $this->maybeApplyDenormStatus($message, $event);
        });
    }

    private function maybeApplyDenormStatus(Message $message, NormalizedStatusEvent $event): void
    {
        if ($message->status === 'failed') {
            return;
        }

        if ($event->status === 'failed') {
            $message->forceFill([
                'status' => 'failed',
                'failed_at' => $event->occurredAt,
                'error_code' => $event->errorCode,
                'error_message' => $event->errorMessage,
            ])->save();

            return;
        }

        $newOrder = self::ORDER[$event->status] ?? null;
        $currentOrder = self::ORDER[$message->status] ?? null;

        if ($newOrder === null || $currentOrder === null || $newOrder <= $currentOrder) {
            return;
        }

        $update = ['status' => $event->status];
        $tsCol = match ($event->status) {
            'sent' => 'sent_at',
            'delivered' => 'delivered_at',
            'read' => 'read_at',
            default => null,
        };
        if ($tsCol !== null) {
            $update[$tsCol] = $event->occurredAt;
        }

        $message->forceFill($update)->save();
    }
}
