<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Services\CatalogLookupService;

/*
| P6-12 — bulk id -> name lookups for another module (Analytics' Affinity page/widget) to show a
| human-readable label next to an id it already has. Read-only; an id with no row simply has no key.
*/

it('maps product ids to names, in no particular order, skipping an id that does not exist', function () {
    $a = Product::factory()->create(['name' => 'Alpha']);
    $b = Product::factory()->create(['name' => 'Beta']);

    $names = app(CatalogLookupService::class)->productNames([$a->id, $b->id, 999_999]);

    expect($names)->toBe([$a->id => 'Alpha', $b->id => 'Beta']);
});

it('returns an empty array for an empty id list, without querying', function () {
    expect(app(CatalogLookupService::class)->productNames([]))->toBe([])
        ->and(app(CatalogLookupService::class)->categoryNames([]))->toBe([]);
});

it('maps category ids to names the same way', function () {
    $cat = ProductCategory::factory()->create(['name' => 'Shirts']);

    expect(app(CatalogLookupService::class)->categoryNames([$cat->id]))->toBe([$cat->id => 'Shirts']);
});
