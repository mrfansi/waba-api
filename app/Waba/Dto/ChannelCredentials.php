<?php

namespace App\Waba\Dto;

final readonly class ChannelCredentials
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public string $driver,
        public string $channelId,
        public array $data,
        public string $webhookSecret,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
