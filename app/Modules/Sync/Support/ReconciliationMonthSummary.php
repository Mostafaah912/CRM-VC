<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Modules\Sync\Enums\ReconciliationStatus;

/** One stored month, as the health page lists it: its status, how far off it was (null when never measured) and, if it failed, why (already scrubbed). */
final readonly class ReconciliationMonthSummary
{
    public function __construct(
        public string $month,
        public ReconciliationStatus $status,
        public ?int $countDiff,
        public ?string $diffPercent,
        public ?string $error,
    ) {}
}
