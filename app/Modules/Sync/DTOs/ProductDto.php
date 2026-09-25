<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

use Carbon\CarbonImmutable;

/**
 * A Woo product. `type` and `status` are Woo's own slugs, carried as-is: they are store/plugin-defined,
 * Sync may not use the Catalog module's Enums, and a value outside Catalog's core set must reach P2-05
 * to be mapped there — not be rejected here. `sku`/`price` are the product's own (PRD §10 step 3 resolves by sku alone).
 */
final readonly class ProductDto
{
    /**
     * @param  list<int>  $wooCategoryIds
     */
    public function __construct(
        public int $wooProductId,
        public string $name,
        public ?string $slug,
        public string $type,
        public string $status,
        public ?string $sku,
        public ?int $price,
        public ?CarbonImmutable $createdAtWoo,
        public array $wooCategoryIds,
    ) {}
}
