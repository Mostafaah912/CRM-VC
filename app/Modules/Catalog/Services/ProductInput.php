<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Carbon\CarbonImmutable;

/**
 * A Woo product with the full set of categories and variations Woo currently reports for it. `type` and
 * `status` are Woo's own slugs; mapping them onto Catalog's enums is Catalog's job.
 *
 * `sku`/`price` are the product's own — not part of PRD §09's literal `products` schema, added so a
 * "simple" product (which never has a variation) can still resolve an order line (P6 decision,
 * ARCHITECTURE.md). Optional with a null default so every existing positional call stays valid.
 */
final readonly class ProductInput
{
    /**
     * @param  list<int>  $wooCategoryIds
     * @param  list<VariationInput>  $variations
     */
    public function __construct(
        public int $wooProductId,
        public string $name,
        public ?string $slug,
        public string $type,
        public string $status,
        public ?CarbonImmutable $createdAtWoo,
        public array $wooCategoryIds,
        public array $variations,
        public ?string $sku = null,
        public ?int $price = null,
    ) {}
}
