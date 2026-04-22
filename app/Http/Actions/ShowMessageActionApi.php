<?php

namespace App\Http\Actions;

use App\Models\Channel;
use App\Models\Message;
use App\Waba\Exceptions\MessageNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowMessageActionApi
{
    public function __invoke(Request $request, string $channelParam, string $id): JsonResponse
    {
        /** @var Channel $channel */
        $channel = $request->attributes->get('channel');

        $m = Message::where('channel_id', $channel->id)->find($id);

        if (! $m) {
            throw MessageNotFoundException::id($id);
        }

        return response()->json([
            'data' => [
                'id' => $m->id,
                'status' => $m->status,
                'channel' => $channel->name,
                'to' => $m->to_number,
                'type' => $m->type,
                'provider_message_id' => $m->provider_message_id,
                'sent_at' => optional($m->sent_at)->toIso8601String(),
                'delivered_at' => optional($m->delivered_at)->toIso8601String(),
                'read_at' => optional($m->read_at)->toIso8601String(),
                'failed_at' => optional($m->failed_at)->toIso8601String(),
                'error_code' => $m->error_code,
                'error_message' => $m->error_message,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ],
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }
}
