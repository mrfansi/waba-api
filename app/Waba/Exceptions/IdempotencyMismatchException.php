<?php

namespace App\Waba\Exceptions;

class IdempotencyMismatchException extends WabaException
{
    public function __construct(string $key)
    {
        parent::__construct("Idempotency key [{$key}] reused with different request body.");
    }

    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
