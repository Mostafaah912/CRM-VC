<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

/** What one refund sync left behind: refunds now stored, refunds removed, and lines that could not be attributed to an item. */
final readonly class RefundSyncSummary
{
    public function __construct(
        public int $refunds,
        public int $removed,
        public int $unattributedLines,
    ) {}
}
