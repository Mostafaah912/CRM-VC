<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooFixtures;
use Tests\Support\WooPayloads;

/*
| P6 decision (ARCHITECTURE.md): a thin Job wrapper around CatalogSyncService — the entry point it never
| had since P2-05 built it (docs/architecture/sprint-6.md, the diagnosis this fixes). Real recorded
| fixtures, FakeWooClient, no HTTP.
*/

it('implements ShouldBeUnique so at most one catalog sync runs at a time', function () {
    expect(in_array(ShouldBeUnique::class, class_implements(CatalogSyncJob::class), true))->toBeTrue();
});

it('sets uniqueFor greater than timeout, per PRD §22\'s rule for every whole-data job', function () {
    $job = new CatalogSyncJob;

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('is queued on sync, not critical (that queue is for webhooks)', function () {
    expect((new CatalogSyncJob)->queue)->toBe('sync');
});

it('syncs categories before products when dispatched, persisting the product\'s own sku/price', function () {
    Http::preventStrayRequests();
    $categories = array_filter(WooFixtures::all(), fn (WooFixture $f) => $f->endpoint === 'products/categories');
    $products = [WooPayloads::items('products')[0]];
    $fake = new FakeWooClient([
        ...$categories,
        new WooFixture('products', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($products)], $products),
    ]);
    app()->instance(WooClient::class, $fake);

    CatalogSyncJob::dispatchSync();

    expect(ProductCategory::count())->toBeGreaterThan(0);
    $product = Product::where('woo_product_id', 101)->sole();
    expect($product->sku)->toBe('SYN-TEE-001')
        ->and($product->price)->toBe(403880)
        ->and($product->categories()->count())->toBeGreaterThan(0);
    Http::assertNothingSent();
});

it('is idempotent: dispatching twice does not duplicate rows', function () {
    Http::preventStrayRequests();
    $categories = array_filter(WooFixtures::all(), fn (WooFixture $f) => $f->endpoint === 'products/categories');
    $products = [WooPayloads::items('products')[0]];
    $fixtures = [
        ...$categories,
        new WooFixture('products', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($products)], $products),
    ];
    app()->instance(WooClient::class, new FakeWooClient($fixtures));
    CatalogSyncJob::dispatchSync();
    $productCount = Product::count();
    $categoryCount = ProductCategory::count();

    app()->instance(WooClient::class, new FakeWooClient($fixtures));
    CatalogSyncJob::dispatchSync();

    expect(Product::count())->toBe($productCount)->and(ProductCategory::count())->toBe($categoryCount);
});
