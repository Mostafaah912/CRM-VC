<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;

/**
 * Bulk id -> name lookups for another module to show a human-readable label next to an id it already
 * has (PRD §07: cross-module access only through a public Service, CLAUDE.md §1) — built for the
 * Affinity page/dashboard widget, which only ever has product_affinities.entity_a_id/entity_b_id.
 * Read-only, no filtering or pagination; an id with no row simply has no key in the result.
 */
final class CatalogLookupService
{
    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function productNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function categoryNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ProductCategory::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
