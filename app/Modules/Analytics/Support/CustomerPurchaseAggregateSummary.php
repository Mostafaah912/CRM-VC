<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/** What CustomerPurchaseAggregateService::rebuild() (P6-01) returns. */
final readonly class CustomerPurchaseAggregateSummary
{
    public function __construct(
        public int $productRows,
        public int $categoryRows,
        public int $elapsedMs,
    ) {}
}
