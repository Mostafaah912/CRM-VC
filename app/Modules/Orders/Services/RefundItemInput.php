<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

/**
 * One refunded line. `originalWooItemId` is the ORDER item it refunds (Woo's own link), or null when Woo gave none —
 * then the line is never attributed. Quantity and amount are positive magnitudes; amount is int Toman.
 */
final readonly class RefundItemInput
{
    public function __construct(
        public int $wooItemId,
        public ?int $originalWooItemId,
        public int $quantity,
        public int $amount,
    ) {}
}
