<?php

namespace App\Restify\Actions;

use App\Models\Channel;
use App\Waba\Facades\Waba;
use Binaryk\LaravelRestify\Actions\Action;
use Binaryk\LaravelRestify\Http\Requests\ActionRequest;
use Illuminate\Database\Eloquent\Model;

class ProbeChannelAction extends Action
{
    public static string $uriKey = 'probe-channel';

    /**
     * @param  Channel|Model  $model  Restify passes the single model when action
     *                                is invoked on a specific repository record.
     */
    public function handle(ActionRequest $request, $model)
    {
        /** @var Channel $channel */
        $channel = $model;

        $ok = Waba::channel($channel->name)->probe();

        if ($ok) {
            $channel->forceFill(['last_verified_at' => now()])->save();
        }

        return data(['ok' => $ok, 'channel' => $channel->name]);
    }
}
