<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mappers;

use App\Modules\Sync\DTOs\VariationDto;
use App\Modules\Sync\Exceptions\WooMappingException;
use InvalidArgumentException;

/** Raw Woo variation object -> VariationDto. The parent product id is the endpoint's, not the payload's. */
final class VariationMapper
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function map(array $raw, int $wooProductId): VariationDto
    {
        if ($wooProductId < 1) {
            throw new InvalidArgumentException('The parent Woo product id must be at least 1.');
        }

        $r = new PayloadReader($raw, 'variation');
        $attributes = [];

        foreach ($r->objects('attributes') as $index => $attribute) {
            $name = $attribute->nonEmptyString('name');

            if (array_key_exists($name, $attributes)) {
                throw new WooMappingException('variation', "attributes.{$index}.name", 'a unique attribute name', 'a repeated name');
            }

            $attributes[$name] = $attribute->string('option');
        }

        return new VariationDto(
            $r->positiveInt('id'),
            $wooProductId,
            $r->nullableString('sku'),
            $r->nullableMoney('price'),
            $r->nonEmptyString('status'),
            $attributes,
        );
    }
}
