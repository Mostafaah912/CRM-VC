<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/**
 * What RetentionService::returningRevenueShare() (P6-04) returns. `$share` is null when
 * `$insufficientData` is true (zero realized revenue at all) — never a fabricated 0.
 */
final readonly class ReturningRevenueShareResult
{
    public function __construct(
        public int $totalRevenue,
        public int $returningRevenue,
        public ?float $share,
        public bool $insufficientData,
    ) {}
}
