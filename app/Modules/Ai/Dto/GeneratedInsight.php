<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/**
 * A validated analyst answer. `source` is "model" or "cache". For a cache hit nothing was called and nothing was spent
 * (attempts 0, no responses, zero tokens/cost). P7-08 persists this into ai_insights (+ ai_tool_calls from the context).
 */
final readonly class GeneratedInsight
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<AiResponse>  $responses  every provider response received (1 or 2)
     */
    public function __construct(
        public array $payload,
        public string $source,
        public string $type,
        public string $promptVersion,
        public string $cacheKey,
        public int $attempts,
        public int $redactions,
        public array $responses,
        public int $inputTokens,
        public int $outputTokens,
        public string $costUsd,
        public ?string $model,
        public int $durationMs,
    ) {}
}
