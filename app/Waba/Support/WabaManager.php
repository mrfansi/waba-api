<?php

namespace App\Waba\Support;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Testing\FakeProvider;
use Illuminate\Contracts\Container\Container;

class WabaManager
{
    private ?FakeProvider $fake = null;

    public function __construct(
        private Container $container,
        private ChannelResolver $resolver,
    ) {}

    public function channel(string $slug): MessageProvider
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        return $this->resolver->resolve($slug);
    }

    public function fake(): FakeProvider
    {
        return $this->fake = new FakeProvider;
    }
}
