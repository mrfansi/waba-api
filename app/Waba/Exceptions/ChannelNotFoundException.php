<?php

namespace App\Waba\Exceptions;

class ChannelNotFoundException extends WabaException
{
    public static function slug(string $slug): self
    {
        return new self("Channel [{$slug}] not found.");
    }

    public function errorCode(): string
    {
        return 'channel_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
