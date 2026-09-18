<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mappers;

use App\Modules\Sync\DTOs\CategoryDto;

/** Raw Woo product-category object -> CategoryDto. */
final class CategoryMapper
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function map(array $raw): CategoryDto
    {
        $r = new PayloadReader($raw, 'category');

        return new CategoryDto(
            $r->positiveInt('id'),
            $r->nonEmptyString('name'),
            $r->nullableString('slug'),
            $r->nullableId('parent'),
        );
    }
}
