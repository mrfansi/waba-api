<?php

namespace App\Waba\Exceptions;

class DriverException extends WabaException
{
    /** @param array<string,mixed> $meta */
    public function __construct(string $message, private array $meta = [])
    {
        parent::__construct($message);
    }

    public static function notImplemented(string $method): self
    {
        return new self("Method {$method} not implemented in P1.", ['stage' => 'p1_stub']);
    }

    public function errorCode(): string
    {
        return 'provider_error';
    }

    public function httpStatus(): int
    {
        return 502;
    }

    public function details(): array
    {
        return $this->meta;
    }
}
