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
        );
    }

    private function item(PayloadReader $r): OrderItemDto
    {
        return new OrderItemDto(
            $r->positiveInt('id'),
            $r->nullableId('product_id'),
            $r->nullableId('variation_id'),
            $r->nullableString('sku'),
            $r->string('name'),
            $r->nonNegativeInt('quantity'),
            $r->money('price'),
            $r->money('subtotal'),
            $r->money('total'),
        );
    }
}
