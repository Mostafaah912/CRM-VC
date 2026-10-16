<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/** A ready-to-send provider request plus what the preview command and P7-08 need to know about it. Building one calls nothing. */
final readonly class AnalystRequest
{
    public function __construct(
        public AiRequest $request,
        public string $type,
        public string $promptVersion,
        public int $systemBytes,
        public int $userBytes,
        public int $contextBytes,
        public int $estimatedInputTokens,
    ) {}
}
