<?php

namespace App\Waba\Exceptions;

use RuntimeException;

abstract class WabaException extends RuntimeException
{
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /** @return array<string,mixed> */
    public function details(): array
    {
        return [];
    }
}
