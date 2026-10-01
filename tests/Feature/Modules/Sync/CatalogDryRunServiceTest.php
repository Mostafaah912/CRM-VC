<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Sync\Services\CatalogDryRunService;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooPayloads;

/*
| P6 decision (ARCHITECTURE.md): a read-only pass over every real Woo product and variation that only
| validates through the P2-03 mappers (WooClient's own generator-based pages(), no eager array building,
| so it stays memory-bounded over the whole catalog) and never calls Catalog at all — no CatalogService
| dependency exists here, so it structurally cannot write. Categories are out of scope (decision 3 asks
| for products and variations only).
*/

function dryRunFake(array $products, array $extra = []): FakeWooClient
{
    return new FakeWooClient([
        new WooFixture('products', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($products)], $products),
        ...$extra,
    ]);
}

function dryRun(FakeWooClient $fake): CatalogDryRunService
{
    app()->instance(WooClient::class, $fake);

    return app(CatalogDryRunService::class);
}

it('validates every product through the mapper and counts the valid ones', function () {
    Http::preventStrayRequests();
    $fake = dryRunFake([WooPayloads::items('products')[0]]);

    $result = dryRun($fake)->scan();

    expect([$result->productsScanned, $result->productsValid, $result->errorClasses])->toBe([1, 1, []]);
    Http::assertNothingSent();
});

it('counts a malformed product as an error class with its count and one sample, and keeps scanning', function () {
    Http::preventStrayRequests();
    $bad = WooPayloads::without(WooPayloads::items('products')[1], 'name');
    $fake = dryRunFake([WooPayloads::items('products')[0], $bad]);

    $result = dryRun($fake)->scan();

    expect([$result->productsScanned, $result->productsValid])->toBe([2, 1])
        ->and($result->errorClasses)->toHaveCount(1);
    $reason = array_key_first($result->errorClasses);
    expect($reason)->toContain('name')
        ->and($result->errorClasses[$reason]['count'])->toBe(1)
        ->and($result->errorClasses[$reason]['sample'])->toContain((string) $bad['id']);
});

it('reads variations only for a variable product and validates them too', function () {
    Http::preventStrayRequests();
    $variable = WooPayloads::set(WooPayloads::items('products')[1], 'id', 103);
    $fake = dryRunFake(
        [WooPayloads::items('products')[0], $variable],
        [new WooFixture('products/103/variations', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2'], WooPayloads::items('products/103/variations'))],
    );

    $result = dryRun($fake)->scan();

    expect([$result->productsScanned, $result->productsValid, $result->variationsScanned, $result->variationsValid])->toBe([2, 2, 2, 2]);
});

it('counts a malformed variation without losing the rest of the scan', function () {
    Http::preventStrayRequests();
    $variable = WooPayloads::set(WooPayloads::items('products')[1], 'id', 103);
    $badVariation = WooPayloads::without(WooPayloads::items('products/103/variations')[0], 'id');
    $fake = dryRunFake(
        [$variable],
        [new WooFixture('products/103/variations', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2'], [$badVariation, WooPayloads::items('products/103/variations')[1]])],
    );

    $result = dryRun($fake)->scan();

    expect([$result->variationsScanned, $result->variationsValid])->toBe([2, 1])
        ->and($result->errorClasses)->toHaveCount(1);
});

it('never touches the database: no CatalogService dependency exists to write with', function () {
    Http::preventStrayRequests();
    $fake = dryRunFake([WooPayloads::items('products')[0]]);

    dryRun($fake)->scan();

    expect(Product::count())->toBe(0)->and(ProductVariation::count())->toBe(0)->and(ProductCategory::count())->toBe(0);
});

it('never requests categories: decision 3 scoped this to products and variations only', function () {
    Http::preventStrayRequests();
    $fake = dryRunFake([WooPayloads::items('products')[0]]);

    dryRun($fake)->scan();

    expect(array_map(fn (array $r) => $r['endpoint'], $fake->requests()))->not->toContain('products/categories');
});
