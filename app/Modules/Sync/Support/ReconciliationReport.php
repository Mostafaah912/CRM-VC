<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Modules\Sync\Enums\ReconciliationStatus;

/**
 * The result of comparing one Jalali month of Woo orders with the local ones (PRD §10). Counts are over ALL statuses;
 * revenue is over the REALIZED statuses on both sides. Differences are Woo minus local (signed); `revenueDiffPct` is the
 * absolute percentage with four decimals ("0.0500"). Green means count_diff = 0 and a variance strictly under 1%.
 */
final readonly class ReconciliationReport
{
    public function __construct(
        public string $jalaliMonth,
        public int $wooOrderCount,
        public int $localOrderCount,
        public int $countDiff,
        public int $wooRevenue,
        public int $localRevenue,
        public int $revenueDiff,
        public string $revenueDiffPct,
        public ReconciliationStatus $status,
    ) {}

    public function isGreen(): bool
    {
        return $this->status === ReconciliationStatus::Green;
    }

    /** One line of counts and a percentage — nothing else. */
    public function summary(): string
    {
        return sprintf('%s: %s (orders %d/%d, revenue diff %s%%)', $this->jalaliMonth, $this->status->value, $this->wooOrderCount, $this->localOrderCount, $this->revenueDiffPct);
    }
}
