<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Enums\ProductStatus;

/** What the product list is narrowed by. Every field is optional and they combine with AND. */
final readonly class ProductListFilters
{
    public function __construct(
        public ?string $name = null,
        public ?string $sku = null,
        public ?ProductStatus $status = null,
    ) {}
}
