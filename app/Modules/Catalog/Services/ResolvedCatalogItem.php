<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

/**
 * What a catalog lookup found for an order line: the local product and, when the match was a variation,
 * its local variation id. `variationId` is null when a "simple" product resolved by its own SKU
 * (P6 decision, `CatalogService::resolveProductBySku()`) — there is no variation to point at.
 */
final readonly class ResolvedCatalogItem
{
    public function __construct(
        public int $productId,
        public ?int $variationId,
    ) {}
}
