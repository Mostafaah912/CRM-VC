<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/** What syncing one page of Woo orders wrote, and whether Woo says more pages follow (for P2-08 to chain). */
final readonly class OrderSyncResult
{
    public function __construct(
        public int $orders,
        public int $items,
        public bool $hasMore,
    ) {}
}
