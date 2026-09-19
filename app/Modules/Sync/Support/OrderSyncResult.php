<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/**
 * What syncing one page of Woo orders wrote, whether Woo says more pages follow, and which of the page's orders need
 * their refunds re-read (P2-08): those whose payload lists refunds, plus those that already hold refund rows locally
 * (so a refund deleted in Woo is mirrored away) — in page order, each once. It only reports; it never fetches refunds.
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
    ) {}
}
