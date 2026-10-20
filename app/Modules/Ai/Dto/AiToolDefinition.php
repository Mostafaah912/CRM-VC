<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/** A read-only tool the model may call. `parameters` is a JSON Schema object. */
final readonly class AiToolDefinition
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = ['type' => 'object', 'properties' => []],
    ) {}
}
