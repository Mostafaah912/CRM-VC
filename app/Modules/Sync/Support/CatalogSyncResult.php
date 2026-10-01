<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/**
 * How many products and variations one catalog product sync wrote (for the sync log P2-08 will keep),
 * and how many products it rejected instead of writing — a malformed payload or a catalog conflict
 * (P6 decision, ARCHITECTURE.md: "an invalid product never stops the run", mirroring the existing
 * "an unresolvable product never rejects an order" rule for Orders, `.claude/rules/sync.md`).
 */
final readonly class CatalogSyncResult
{
    public function __construct(
        public int $products,
        public int $variations,
        public int $rejectedProducts = 0,
    ) {}
}
