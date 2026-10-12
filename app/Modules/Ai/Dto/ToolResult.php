<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/**
 * Outcome of one tool execution. `arguments` are the VALIDATED ones (empty when validation failed — the raw model input is
 * never kept), `error` is a model-safe sentence. Persisting this into ai_tool_calls is P7-08's job.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $name,
        public array $arguments,
        public array $result,
        public int $resultSize,
        public int $durationMs,
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /** The JSON the model receives as the tool's answer. */
    public function toModelJson(): string
    {
        return (string) json_encode($this->error === null ? $this->result : ['error' => $this->error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
