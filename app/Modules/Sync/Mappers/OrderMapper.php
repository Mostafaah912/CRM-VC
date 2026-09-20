<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mappers;

use App\Modules\Sync\DTOs\OrderDto;
use App\Modules\Sync\DTOs\OrderItemDto;

/**
 * Raw Woo order object (with its line items) -> OrderDto. Pure transformation: no identity
 * resolution, no status classification, no phone parsing, no DB.
 */
final class OrderMapper
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function map(array $raw): OrderDto
    {
        $r = new PayloadReader($raw, 'order');
        $billing = $r->object('billing');
        $customerId = $r->nonNegativeInt('customer_id');

        return new OrderDto(
            $r->positiveInt('id'),
            $r->nullableString('number'),
            $r->nonEmptyString('status'),
            $r->nonEmptyString('currency'),
            $customerId === 0 ? null : $customerId,
            $billing->string('first_name'),
            $billing->string('last_name'),
            $billing->nullableString('phone'),
            $r->money('total'),
            $r->money('discount_total'),
            $r->money('shipping_total'),
            $r->money('total_tax'),
            array_map(fn (PayloadReader $coupon): string => $coupon->nonEmptyString('code'), $r->objectsOrEmpty('coupon_lines')),
            $r->nullableString('payment_method'),
            $r->date('date_created_gmt'),
            $r->nullableDate('date_paid_gmt'),
            $r->nullableDate('date_completed_gmt'),
            $r->date('date_modified_gmt'),
            array_map($this->item(...), $r->objects('line_items')),
            $r->nullableObjectCount('refunds'),
        );
    }

    /**
     * Woo's line-item `price` is NOT read: it is a derived float (the discounted line total / quantity — live order 22539 sent
     * 63649.75 for quantity 4, total 254599), and the store's amounts are whole Toman everywhere else. The unit price is the
     * price actually paid, floor(line total / quantity), in integer arithmetic (both are non-negative, so intdiv is the
     * floor); 0 when the quantity is 0. The line subtotal and total are the whole strings Woo sends, untouched.
     */
    private function item(PayloadReader $r): OrderItemDto
    {
        $quantity = $r->nonNegativeInt('quantity');
        $lineTotal = $r->money('total');

        return new OrderItemDto(
            $r->positiveInt('id'),
            $r->nullableId('product_id'),
            $r->nullableId('variation_id'),
            $r->nullableString('sku'),
            $r->string('name'),
            $quantity,
            $quantity === 0 ? 0 : intdiv($lineTotal, $quantity),
            $r->money('subtotal'),
            $lineTotal,
        );
    }
}
