<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/** What one OrderRealizedBackfillService::reclassify() run changed (P7-14). */
final readonly class RealizedReclassifyResult
{
    public function __construct(
        public int $madeRealized,
        public int $madeNotRealized,
    ) {}

    public function total(): int
    {
        return $this->madeRealized + $this->madeNotRealized;
    }
}
