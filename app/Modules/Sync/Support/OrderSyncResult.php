<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use Carbon\CarbonImmutable;

/**
 * What syncing one page of Woo orders wrote, whether Woo says more pages follow, which of the page's orders need their
 * refunds re-read (P2-08), and the latest modified time among the page's orders (P2-10). Refund orders are those whose
 * payload lists refunds plus those that already hold refund rows locally (so a refund deleted in Woo is mirrored away),
 * in page order, each once; it only reports, it never fetches refunds. `lastModifiedAt` is where a run that stops early
 * (the page limit) moves the cursor to — the list is ordered by modified — and is null for a page without orders.
 */
final readonly class OrderSyncResult
{
    /**
     * @param  list<int>  $refundOrderIds  Woo order ids
     */
    public function __construct(
        public int $orders,
        public int $items,
        public bool $hasMore,
        public array $refundOrderIds,
        public ?CarbonImmutable $lastModifiedAt,
    ) {}
}
