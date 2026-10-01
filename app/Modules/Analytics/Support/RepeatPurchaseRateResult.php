<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/**
 * What RetentionService::repeatPurchaseRate() (P6-04) returns. `$rate` is null when
 * `$insufficientData` is true (zero customers have ever placed a realized order) — never a fabricated 0.
 */
final readonly class RepeatPurchaseRateResult
{
    public function __construct(
        public int $eligibleCustomers,
        public int $repeatCustomers,
        public ?float $rate,
        public bool $insufficientData,
    ) {}
}
