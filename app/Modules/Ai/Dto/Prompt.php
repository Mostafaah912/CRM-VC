<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/** One versioned prompt file (resources/prompts/*.md). `version` is what ai_insights.prompt_version stores (varchar(20)). */
final readonly class Prompt
{
    public function __construct(
        public string $version,
        public string $type,
        public string $system,
        public string $userTemplate,
        public string $sha256,
    ) {}
}
