<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class LocationPayload
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name = null,
        public ?string $address = null,
    ) {}
}
