<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

final readonly class AiToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}
}
