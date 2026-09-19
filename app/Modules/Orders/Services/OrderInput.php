<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use Carbon\CarbonImmutable;

/**
 * A Woo order with its complete item set. Orders depends on Core, Customers and Catalog only (PRD §07), so it
 * cannot take Sync's DTOs; the caller copies the fields. There is no email on purpose: email is never an
 * identity key. `status` is Woo's raw slug; there is no refund field — refunds are P2-07's.
 */
final readonly class OrderInput
{
    /**
     * @param  list<string>  $couponCodes
     * @param  list<OrderItemInput>  $items
     */
    public function __construct(
        public int $wooOrderId,
        public ?string $number,
        public string $status,
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
