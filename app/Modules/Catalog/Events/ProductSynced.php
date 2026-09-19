<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A product (with its links and variations) was written by sync (PRD §07). Ids only. */
final class ProductSynced
{
    use Dispatchable;

    public function __construct(
        public readonly int $productId,
        public readonly int $wooProductId,
    ) {}
}
