<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Orders\Services\RefundInput;
use App\Modules\Orders\Services\RefundItemInput;
use App\Modules\Orders\Services\RefundService;
use App\Modules\Orders\Services\RefundSyncSummary;
use App\Modules\Sync\DTOs\RefundDto;
use App\Modules\Sync\DTOs\RefundItemDto;
use App\Modules\Sync\Mappers\RefundMapper;
use InvalidArgumentException;

/**
 * Woo -> Orders refunds (P2-07): reads EVERY page of one order's refunds through the WooClient contract, maps ALL of
 * them with the P2-03 RefundMapper, and only then hands the complete set to Orders' public RefundService. Reading
 * and mapping finish before any write, so a failed, partial or malformed fetch can never delete or change anything.
 * No HTTP, models or database here; which orders to sync, when, and the jobs around it are P2-08.
 */
final class RefundSyncService
{
    public function __construct(
        private readonly WooClient $woo,
        private readonly RefundService $refunds,
        private readonly RefundMapper $mapper = new RefundMapper,
    ) {}

    public function syncOrder(int $wooOrderId): RefundSyncSummary
    {
        if ($wooOrderId < 1) {
            throw new InvalidArgumentException('The Woo order id must be at least 1.');
        }

        $dtos = [];

        foreach ($this->woo->pages("orders/{$wooOrderId}/refunds") as $page) {
            foreach ($page->items as $raw) {
                $dtos[] = $this->mapper->map($raw, $wooOrderId);
            }
        }

        return $this->refunds->sync($wooOrderId, array_map($this->input(...), $dtos));
    }

    private function input(RefundDto $r): RefundInput
    {
        return new RefundInput(
            $r->wooRefundId,
            $r->amount,
            $r->reason,
            $r->refundedAt,
            array_map(fn (RefundItemDto $i) => new RefundItemInput($i->wooItemId, $i->originalWooItemId, $i->quantity, $i->amount), $r->items),
        );
    }
}
