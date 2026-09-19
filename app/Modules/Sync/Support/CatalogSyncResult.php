<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/** How many products and variations one catalog product sync wrote (for the sync log P2-08 will keep). */
final readonly class CatalogSyncResult
{
    public function __construct(
        public int $products,
        public int $variations,
    ) {}
}
