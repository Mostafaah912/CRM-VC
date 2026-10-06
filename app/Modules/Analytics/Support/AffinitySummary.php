<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/** What AffinityService::rebuild() (P6-05) wrote, per level. */
final readonly class AffinitySummary
{
    public function __construct(
        public int $categoryRows,
        public int $productRows,
        public int $variationRows,
        public int $basketRows,
        public int $elapsedMs,
    ) {}
}
