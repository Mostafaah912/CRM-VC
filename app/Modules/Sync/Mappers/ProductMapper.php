<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mappers;

use App\Modules\Sync\DTOs\ProductDto;

/** Raw Woo product object -> ProductDto. Type and status pass through as Woo's own strings. */
final class ProductMapper
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function map(array $raw): ProductDto
    {
        $r = new PayloadReader($raw, 'product');
        $dropped = null;
        $price = $r->lenientMoney('price', function (mixed $raw) use (&$dropped): void {
            $dropped = is_string($raw) ? $raw : (json_encode($raw) ?: 'unrepresentable');
        });

        return new ProductDto(
            $r->positiveInt('id'),
            $r->nonEmptyString('name'),
            $r->nullableString('slug'),
            $r->nonEmptyString('type'),
            $r->nonEmptyString('status'),
            $r->nullableString('sku'),
            $price,
            $r->nullableDate('date_created_gmt'),
            array_map(fn (PayloadReader $category): int => $category->positiveInt('id'), $r->objects('categories')),
            $dropped,
        );
    }
}
