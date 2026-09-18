<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mappers;

use App\Modules\Sync\DTOs\RefundDto;
use App\Modules\Sync\DTOs\RefundItemDto;
use InvalidArgumentException;

/**
 * Raw Woo refund object -> RefundDto. Woo's negative quantities/totals become positive magnitudes.
 * The order id is the endpoint's, not the payload's. No is_full, no totals across refunds (P2-07).
 */
final class RefundMapper
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function map(array $raw, int $wooOrderId): RefundDto
    {
        if ($wooOrderId < 1) {
            throw new InvalidArgumentException('The Woo order id must be at least 1.');
        }

        $r = new PayloadReader($raw, 'refund');

        return new RefundDto(
            $r->positiveInt('id'),
            $wooOrderId,
            $r->money('amount'),
            $r->nullableString('reason'),
            $r->date('date_created_gmt'),
            array_map($this->item(...), $r->objects('line_items')),
        );
    }

    private function item(PayloadReader $r): RefundItemDto
    {
        return new RefundItemDto(
            $r->positiveInt('id'),
            $r->nullableId('product_id'),
            $r->nullableId('variation_id'),
            $r->nullableString('sku'),
            $r->absoluteInt('quantity'),
            $r->absoluteMoney('total'),
        );
    }
}
