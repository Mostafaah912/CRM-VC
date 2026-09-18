<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

use Carbon\CarbonImmutable;

/**
 * A Woo order exactly as Woo described it. Nothing is derived: `status` is the raw slug (is_realized
 * is decided later by Orders\Services\OrderStatusMapper), `billingPhone` is untouched (PhoneNormalizer
 * runs at identity resolution, P2-04), `wooCustomerId` is null for a guest (Woo sends 0), and there is no
 * subtotal — Woo has none on the order; it is the sum of the items' `lineSubtotal`. Amounts are int Toman
 * in `currency` (the caller must check it is the store's unit). Timestamps are UTC.
 */
final readonly class OrderDto
{
    /**
     * @param  list<string>  $couponCodes
     * @param  list<OrderItemDto>  $items
     */
    public function __construct(
        public int $wooOrderId,
        public ?string $number,
        public string $status,
        public string $currency,
        public ?int $wooCustomerId,
        public string $billingFirstName,
        public string $billingLastName,
        public ?string $billingPhone,
        public int $total,
        public int $discountTotal,
        public int $shippingTotal,
        public int $taxTotal,
        public array $couponCodes,
        public ?string $paymentMethod,
        public CarbonImmutable $orderedAt,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $completedAt,
        public CarbonImmutable $wooModifiedAt,
        public array $items,
    ) {}
}
