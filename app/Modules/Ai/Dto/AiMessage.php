<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

use App\Modules\Ai\Enums\AiMessageRole;

/**
 * One conversation turn. An assistant turn may carry `toolCalls`; a tool turn answers one of them
 * (`toolCallId` + `toolName`, `content` = the tool's JSON result).
 */
final readonly class AiMessage
{
    /** @param list<AiToolCall> $toolCalls */
    public function __construct(
        public AiMessageRole $role,
        public string $content = '',
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
    ) {}

    public static function user(string $content): self
    {
        return new self(AiMessageRole::User, $content);
    }

    /** @param list<AiToolCall> $toolCalls */
    public static function assistant(string $content = '', array $toolCalls = []): self
    {
        return new self(AiMessageRole::Assistant, $content, $toolCalls);
    }

    public static function tool(string $toolCallId, string $toolName, string $content): self
    {
        return new self(AiMessageRole::Tool, $content, [], $toolCallId, $toolName);
    }
}
