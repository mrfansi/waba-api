<?php

namespace App\Waba\Dto;

final readonly class TemplateDefinition
{
    /** @param array<int,array<string,mixed>> $components */
    public function __construct(public string $name, public string $language, public array $components = []) {}
}
