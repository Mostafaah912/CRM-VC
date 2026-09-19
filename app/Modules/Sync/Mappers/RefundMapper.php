<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mappers;

use App\Modules\Sync\DTOs\RefundDto;
use App\Modules\Sync\DTOs\RefundItemDto;
use App\Modules\Sync\Exceptions\WooMappingException;
use InvalidArgumentException;

/**
 * Raw Woo refund object -> RefundDto. Woo's negative quantities/totals become positive magnitudes.
 * The order id is the endpoint's, not the payload's. No is_full, no totals across refunds (P2-07).
 */
final class RefundMapper
{
    private const ORIGINAL_ITEM_META = '_refunded_item_id';

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
            $this->originalItemId($r),
        );
    }

    /** The order item this refund line refunds, from Woo's own meta; null when there is none. Malformed is an error. */
    private function originalItemId(PayloadReader $line): ?int
    {
        $id = null;

        foreach ($line->objectsOrEmpty('meta_data') as $entry) {
            if ($entry->string('key') !== self::ORIGINAL_ITEM_META) {
                continue;
            }

            if ($id !== null) {
                throw new WooMappingException('refund', $entry->field('key'), 'a single '.self::ORIGINAL_ITEM_META.' entry', 'a repeated entry');
            }

            $id = $entry->wooId('value');
        }

        return $id;
    }
}
