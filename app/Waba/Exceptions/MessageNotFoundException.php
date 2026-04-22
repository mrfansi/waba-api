<?php

namespace App\Waba\Exceptions;

class MessageNotFoundException extends WabaException
{
    public static function id(string $id): self
    {
        return new self("Message [{$id}] not found.");
    }

    public function errorCode(): string
    {
        return 'message_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
