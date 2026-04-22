<?php

namespace App\Waba\Dto;

final readonly class TemplateSyncResult
{
    public function __construct(public string $providerTemplateId, public string $status) {}
}
