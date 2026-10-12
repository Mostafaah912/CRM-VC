<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

use InvalidArgumentException;

final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  list<AiToolDefinition>  $tools
     * @param  array<string, mixed>|null  $responseSchema  JSON Schema the final answer must match
     */
    public function __construct(
        public string $systemPrompt,
        public array $messages,
        public array $tools = [],
        public ?array $responseSchema = null,
        public int $maxOutputTokens = 2048,
        public float $temperature = 0.2,
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('An AI request needs at least one message.');
        }

        if ($maxOutputTokens < 1) {
            throw new InvalidArgumentException('maxOutputTokens must be positive.');
        }

        if ($temperature < 0.0 || $temperature > 2.0) {
            throw new InvalidArgumentException('temperature must be between 0 and 2.');
        }
    }
}
