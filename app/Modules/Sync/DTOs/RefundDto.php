<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

use Carbon\CarbonImmutable;

/**
 * A Woo refund. Woo's refund payload does not name its order, so `wooOrderId` comes from the endpoint.
 * `amount` is a positive int Toman. Whether it is a full refund and what it adds up to on the order
 * (refunded_total is RECOMPUTED, never incremented) is decided by refund sync, not here.
 */
final readonly class RefundDto
{
    /**
     * @param  list<RefundItemDto>  $items
     */
    public function __construct(
        public int $wooRefundId,
        public int $wooOrderId,
        public int $amount,
        public ?string $reason,
        public CarbonImmutable $refundedAt,
        public array $items,
    ) {}
}
