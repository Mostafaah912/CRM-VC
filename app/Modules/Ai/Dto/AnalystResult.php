<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

use App\Modules\Ai\Models\AiInsight;

/** What generate() returns: the stored (or reused) insight row, and whether it came from the cache (then nothing was called or spent). */
final readonly class AnalystResult
{
    public function __construct(
        public AiInsight $insight,
        public bool $fromCache,
        public ?GeneratedInsight $generated,
        public int $toolCalls,
    ) {}
}
