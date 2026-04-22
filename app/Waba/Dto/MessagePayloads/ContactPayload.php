<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class ContactPayload
{
    /** @param array<int,array<string,mixed>> $contacts */
    public function __construct(public array $contacts) {}
}
