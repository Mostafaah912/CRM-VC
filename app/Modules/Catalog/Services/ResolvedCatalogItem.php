<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

/** What a catalog lookup found for an order line: the local variation and its local product (ids only). */
final readonly class ResolvedCatalogItem
{
    public function __construct(
        public int $productId,
        public int $variationId,
    ) {}
}
