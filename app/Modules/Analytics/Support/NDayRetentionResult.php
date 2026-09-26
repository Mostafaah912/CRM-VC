<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/**
 * What RetentionService::nDayRetention() (P6-04) returns. `$retentionRate` is null when
 * `$insufficientData` is true (zero mature customers — PRD §15's "insufficient data" case) — never a
 * fabricated 0, and never computed by including an immature customer just to have a denominator.
 */
final readonly class NDayRetentionResult
{
    public function __construct(
        public int $days,
        public int $matureCustomers,
        public int $returnedCustomers,
        public ?float $retentionRate,
        public bool $insufficientData,
    ) {}
}
