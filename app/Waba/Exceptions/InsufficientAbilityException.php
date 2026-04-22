<?php

namespace App\Waba\Exceptions;

class InsufficientAbilityException extends WabaException
{
    public function __construct(private string $ability)
    {
        parent::__construct("Missing ability: {$ability}");
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['required_ability' => $this->ability];
    }
}
