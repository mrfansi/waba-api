<?php

namespace App\Waba\Dto;

final readonly class MediaUpload
{
    public function __construct(public string $path, public string $mime, public string $filename) {}
}
