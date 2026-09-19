<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

/**
 * One refunded line. Woo sends quantity and total negative; both are carried as positive magnitudes.
 * `wooItemId` is the refund line's OWN id. `originalWooItemId` is the ORDER item it refunds — Woo's meta
 * `_refunded_item_id` (confirmed on the live store, P2-07) — and is null when Woo gave no link: such a line is
 * never matched by product, variation or SKU.
 */
final readonly class RefundItemDto
{
    public function __construct(
        public int $wooItemId,
        public ?int $wooProductId,
        public ?int $wooVariationId,
        public ?string $sku,
        public int $quantity,
        public int $amount,
        public ?int $originalWooItemId,
    ) {}
}
