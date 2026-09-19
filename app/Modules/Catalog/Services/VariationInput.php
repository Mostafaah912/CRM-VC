<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

/**
 * One Woo variation of the product it is passed with — the parent is never named separately, so a
 * variation cannot be attached to the wrong product. Price is int Toman; attributes map name => option.
 */
final readonly class VariationInput
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public int $wooVariationId,
        public ?string $sku,
        public ?int $price,
        public string $status,
        public array $attributes,
    ) {}
}
