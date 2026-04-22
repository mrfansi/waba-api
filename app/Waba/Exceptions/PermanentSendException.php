<?php

namespace App\Waba\Exceptions;

class PermanentSendException extends WabaException
{
    /** @param array<string,mixed> $meta */
    public function __construct(string $message, private array $meta = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'provider_rejected';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return $this->meta;
    }
}
