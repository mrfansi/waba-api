<?php

namespace App\Http\Actions;

use App\Http\Requests\SendMessageRequest;
use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Waba\Outbound\DispatchService;
use Illuminate\Http\JsonResponse;

class SendMessageActionApi
{
    public function __invoke(SendMessageRequest $request, DispatchService $dispatcher): JsonResponse
    {
        /** @var Channel $channel */
        $channel = $request->attributes->get('channel');
        /** @var ChannelApiKey $apiKey */
        $apiKey = $request->attributes->get('apiKey');

        $message = $dispatcher->dispatch($channel, $apiKey, $request->toDto(), $request->mode());

        $replay = (bool) ($message->wasIdempotentReplay ?? false);
        $statusCode = $request->mode() === 'sync' ? 200 : ($replay ? 200 : 202);

        $response = response()->json([
            'data' => [
                'id' => $message->id,
                'status' => $message->status,
                'channel' => $channel->name,
                'to' => $message->to_number,
                'type' => $message->type,
                'provider_message_id' => $message->provider_message_id,
                'sent_at' => optional($message->sent_at)->toIso8601String(),
                'queued_at' => optional($message->created_at)->toIso8601String(),
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], $statusCode);

        if ($replay) {
            $response->header('X-Idempotent-Replay', 'true');
        }

        return $response;
    }
}
