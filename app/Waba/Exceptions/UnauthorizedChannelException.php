<?php

namespace App\Waba\Exceptions;

class UnauthorizedChannelException extends WabaException
{
    public function errorCode(): string
    {
        return 'unauthorized';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
