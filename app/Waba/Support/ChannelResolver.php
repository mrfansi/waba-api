<?php

namespace App\Waba\Support;

use App\Models\Channel;
use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Exceptions\ChannelNotFoundException;
use App\Waba\Exceptions\DriverException;
use Illuminate\Contracts\Container\Container;

class ChannelResolver
{
    public function __construct(private Container $container) {}

    public function resolve(string $slug): MessageProvider
    {
        $channel = Channel::query()
            ->where('name', $slug)
            ->where('status', 'active')
            ->first();

        if (! $channel) {
            throw ChannelNotFoundException::slug($slug);
        }

        return $this->fromModel($channel);
    }

    public function fromModel(Channel $channel): MessageProvider
    {
        $class = config("waba.providers.{$channel->driver}.class");

        if (! $class || ! class_exists($class)) {
            throw new DriverException("No driver registered for [{$channel->driver}]");
        }

        /** @var MessageProvider $driver */
        $driver = $this->container->make($class);

        return $driver->bind(new ChannelCredentials(
            driver: $channel->driver,
            channelId: (string) $channel->id,
            data: $channel->credentials ?? [],
            webhookSecret: $channel->webhook_secret,
        ));
    }
}
