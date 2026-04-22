<?php

namespace App\Http\Webhooks;

use App\Models\Channel;
use App\Waba\Facades\Waba;
use App\Waba\Outbound\StatusRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandleInboundWebhook
{
    public function __invoke(Request $request, string $provider, string $channel, StatusRecorder $recorder): JsonResponse
    {
        $providerInstance = Waba::channel($channel);
        $payload = $request->json()->all();

        $statusEvent = $providerInstance->normalizeStatus($payload);
        if ($statusEvent !== null) {
            $channelRow = Channel::where('name', $channel)->firstOrFail();
            $recorder->record($channelRow, $statusEvent, $payload);

            return response()->json(['accepted' => true], 202);
        }

        return response()->json(['accepted' => true, 'note' => 'inbound_p3_pending'], 202);
    }
}
