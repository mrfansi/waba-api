<?php

namespace App\Restify;

use App\Models\Message;
use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;

class MessageRepository extends Repository
{
    public static string $model = Message::class;

    public static array $search = ['provider_message_id', 'to_number'];

    public static array $match = [
        'channel_id' => 'string',
        'status' => 'string',
        'type' => 'string',
        'direction' => 'string',
    ];

    public function fields(RestifyRequest $request): array
    {
        return [
            id(),
            field('channel_id')->readonly(),
            field('direction')->readonly(),
            field('to_number')->readonly(),
            field('from_number')->readonly(),
            field('type')->readonly(),
            field('payload')->readonly(),
            field('status')->readonly(),
            field('provider_message_id')->readonly(),
            field('idempotency_key')->readonly(),
            field('error_code')->readonly(),
            field('error_message')->readonly(),
            field('attempts')->readonly(),
            field('sent_at')->datetime()->readonly(),
            field('delivered_at')->datetime()->readonly(),
            field('read_at')->datetime()->readonly(),
            field('failed_at')->datetime()->readonly(),
            field('created_at')->datetime()->readonly(),
        ];
    }
}
