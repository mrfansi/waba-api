<?php

namespace App\Restify;

use App\Models\Channel;
use App\Restify\Actions\ProbeChannelAction;
use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;

class ChannelRepository extends Repository
{
    public static string $model = Channel::class;

    public static array $search = ['name', 'display_name', 'phone_number'];

    public static array $match = ['driver' => 'string', 'status' => 'string'];

    public function actions(RestifyRequest $request): array
    {
        return [
            ProbeChannelAction::make(),
        ];
    }

    public function fields(RestifyRequest $request): array
    {
        return [
            id(),
            field('name')->required(),
            field('display_name')->required(),
            field('driver')->required(),
            field('phone_number')->required(),
            field('phone_number_id')->nullable(),
            // hideFromIndex+hideFromShow suppresses output at the Restify layer.
            // hidden() would also block fillAttribute from reading request input,
            // so we use the targeted hide methods here. The model's $hidden
            // property provides a second serialisation guard.
            field('credentials')->hideFromIndex()->hideFromShow(),
            field('webhook_secret')->hideFromIndex()->hideFromShow(),
            field('settings')->nullable(),
            field('status')->rules('in:active,disabled,pending'),
            field('last_verified_at')->datetime()->readonly(),
            field('created_at')->datetime()->readonly(),
            field('updated_at')->datetime()->readonly(),
        ];
    }
}
