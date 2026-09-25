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

        return new ProductDto(
            $r->positiveInt('id'),
            $r->nonEmptyString('name'),
            $r->nullableString('slug'),
            $r->nonEmptyString('type'),
            $r->nonEmptyString('status'),
            $r->nullableString('sku'),
            $r->nullableMoney('price'),
            $r->nullableDate('date_created_gmt'),
            array_map(fn (PayloadReader $category): int => $category->positiveInt('id'), $r->objects('categories')),
        );
    }
}
