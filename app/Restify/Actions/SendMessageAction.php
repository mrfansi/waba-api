<?php

namespace App\Restify\Actions;

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Outbound\DispatchService;
use Binaryk\LaravelRestify\Actions\Action;
use Binaryk\LaravelRestify\Http\Requests\ActionRequest;

class SendMessageAction extends Action
{
    public static string $uriKey = 'send-message';

    public function handle(ActionRequest $request, $model)
    {
        /** @var Channel $channel */
        $channel = $model;

        $type = $request->input('type', 'text');
        $to = $request->input('to');

        $payload = match ($type) {
            'text' => new TextPayload(
                body: $request->input('text.body', ''),
                previewUrl: (bool) $request->input('text.preview_url', false),
            ),
            default => new TextPayload(body: $request->input('text.body', ''), previewUrl: false),
        };

        $dto = new OutboundMessage(to: $to, type: $type, payload: $payload);

        $apiKey = ChannelApiKey::where('channel_id', $channel->id)->active()->first()
            ?? ChannelApiKey::factory()->for($channel)->create(['abilities' => ['*']]);

        $message = app(DispatchService::class)->dispatch($channel, $apiKey, $dto, 'sync');

        return response()->json([
            'data' => [
                'id' => $message->id,
                'status' => $message->status,
                'channel' => $channel->name,
                'provider_message_id' => $message->provider_message_id,
            ],
        ]);
    }
}
