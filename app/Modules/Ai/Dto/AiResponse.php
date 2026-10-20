<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/**
 * Provider-independent result. `json` is set when a response schema was requested and the text parsed
 * as a JSON object; `reasoningTokens` is the hidden-thinking part of `outputTokens` (already included in it, billed as output); `costUsd` is a 5-decimal string (ai_insights.cost_usd is numeric(10,5)) — never a float.
 */
final readonly class AiResponse
{
    /** @param list<AiToolCall> $toolCalls
     *  @param array<string, mixed>|null $json */
    public function __construct(
        public ?string $text,
        public ?array $json,
        public array $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public string $costUsd,
        public string $model,
        public string $provider,
        public int $durationMs,
        public int $reasoningTokens = 0,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
