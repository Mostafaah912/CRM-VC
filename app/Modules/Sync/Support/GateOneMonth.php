<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Modules\Sync\Enums\ReconciliationStatus;

/** One month of the GATE 1 check: its stored status (null = never reconciled) and, when measured, how far off it was. */
final readonly class GateOneMonth
{
    public function __construct(
        public string $month,
        public ?ReconciliationStatus $status,
        public ?int $countDiff,
        public ?string $revenueDiffPct,
    ) {}

    public function isGreen(): bool
    {
        return $this->status === ReconciliationStatus::Green;
    }
}
