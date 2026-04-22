<?php

namespace App\Waba\Dto;

final readonly class MediaReference
{
    public function __construct(public string $id, public string $mime, public ?string $url = null) {}
}
