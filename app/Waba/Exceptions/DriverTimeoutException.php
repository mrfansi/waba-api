<?php

namespace App\Waba\Exceptions;

class DriverTimeoutException extends DriverException
{
    public function errorCode(): string
    {
        return 'provider_timeout';
    }

    public function httpStatus(): int
    {
        return 504;
    }
}
