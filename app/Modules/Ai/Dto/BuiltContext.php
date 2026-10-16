<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/**
 * The analyst's input for one run. `json` is deterministic (same data and day => same bytes; no timestamps of "now", no
 * durations) so P7-07 can hash it into a cache_key. `toolResults` are the ToolResults whose output is INSIDE the json, for
 * P7-08 to persist in ai_tool_calls (durations live there, not in the json). `truncations` says what was cut to fit.
 */
final readonly class BuiltContext
{
    /**
     * @param  list<ToolResult>  $toolResults
     * @param  list<array{section: string, action: string, detail: string}>  $truncations
     * @param  array<string, int>  $sectionBytes
     */
    public function __construct(
        public string $type,
        public string $json,
        public int $sizeBytes,
        public int $maxBytes,
        public int $estimatedTokens,
        public bool $truncated,
        public array $truncations,
        public array $sectionBytes,
        public array $toolResults,
        public int $redactions = 0,
    ) {}
}
