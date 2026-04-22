<?php

namespace App\Http\Actions;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListMessagesActionApi
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Channel $channel */
        $channel = $request->attributes->get('channel');

        $rows = Message::where('channel_id', $channel->id)
            ->orderByDesc('created_at')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Message $m) => $this->present($m, $channel))->values(),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    /** @return array<string,mixed> */
    private function present(Message $m, Channel $channel): array
    {
        return [
            'id' => $m->id,
            'status' => $m->status,
            'channel' => $channel->name,
            'to' => $m->to_number,
            'type' => $m->type,
            'provider_message_id' => $m->provider_message_id,
            'sent_at' => optional($m->sent_at)->toIso8601String(),
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }
}
