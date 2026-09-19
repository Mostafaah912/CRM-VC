<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Carbon\CarbonImmutable;

/**
 * A Woo product with the full set of categories and variations Woo currently reports for it. `type` and
 * `status` are Woo's own slugs; mapping them onto Catalog's enums is Catalog's job.
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
    ) {}
}
