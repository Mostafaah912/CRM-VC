<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CatalogIntegrityException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Services\CatalogSyncService;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooFixtures;
use Tests\Support\WooPayloads;

/*
| P2-05 — the Woo -> Catalog flow: WooClient (here the P2-02 FakeWooClient, so no HTTP) -> P2-03 mappers
| -> CatalogService. Only the recorded fixtures feed it; the tests never write raw Woo JSON parsing of
| their own. Variations are read per VARIABLE product from its own endpoint, then written in the same
| unit of work as the product.
*/

$GLOBALS['sync_logs'] = [];

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
    $GLOBALS['sync_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $GLOBALS['sync_logs'][] = ['level' => $e->level, 'context' => $e->context];
    });
});

/** The recorded variable product 102, re-numbered to 103 so it lines up with the recorded products/103/variations. */
function variableProduct103(): array
{
    $raw = WooPayloads::items('products')[1];
    $raw = WooPayloads::set($raw, 'id', 103);
    $raw = WooPayloads::set($raw, 'sku', 'SYN-SHIRT-103');
    $raw = WooPayloads::set($raw, 'slug', 'synthetic-shirt');

    return WooPayloads::set($raw, 'categories', [['id' => 31, 'name' => 'پوشاک آزمایشی'], ['id' => 32, 'name' => 'کت آزمایشی']]);
}

/** @param  list<WooFixture>  $extra */
function catalogFake(?array $products = null, array $extra = [], bool $withVariations = true): FakeWooClient
{
    $kept = array_filter(
        WooFixtures::all(),
        fn (WooFixture $f) => $f->endpoint === 'products/categories' || ($withVariations && $f->endpoint === 'products/103/variations'),
    );
    $products ??= [WooPayloads::items('products')[0], variableProduct103()];

    return new FakeWooClient([
        ...$kept,
        new WooFixture('products', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($products)], $products),
        ...$extra,
    ]);
}

function catalogSync(FakeWooClient $fake): CatalogSyncService
{
    app()->instance(WooClient::class, $fake);

    return app(CatalogSyncService::class);
}

function endpointsRequested(FakeWooClient $fake): array
{
    return array_map(fn (array $r) => "{$r['endpoint']}#{$r['page']}", $fake->requests());
}

// ---------------------------------------------------------------- categories

it('syncs the recorded categories through the mapper into local rows, with the parent link', function () {
    $fake = catalogFake();

    $count = catalogSync($fake)->syncCategories();

    expect($count)->toBe(2)
        ->and(ProductCategory::count())->toBe(2)
        ->and(ProductCategory::where('woo_category_id', 32)->sole()->parent?->woo_category_id)->toBe(31)
        ->and(ProductCategory::where('woo_category_id', 31)->sole()->slug)->toBe('synthetic-apparel')
        ->and(endpointsRequested($fake))->toBe(['products/categories#1']);
    Http::assertNothingSent();
});

it('is idempotent for categories', function () {
    $sync = catalogSync(catalogFake());

    $sync->syncCategories();
    $ids = ProductCategory::orderBy('woo_category_id')->pluck('id')->all();
    $sync->syncCategories();

    expect(ProductCategory::orderBy('woo_category_id')->pluck('id')->all())->toBe($ids)->and(ProductCategory::count())->toBe(2);
});

it('maps every category before writing any: one malformed payload writes nothing', function () {
    $good = WooPayloads::items('products/categories')[0];
    $bad = WooPayloads::without(WooPayloads::items('products/categories')[1], 'id');
    $fake = new FakeWooClient([new WooFixture('products/categories', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2'], [$good, $bad])]);

    expect(fn () => catalogSync($fake)->syncCategories())->toThrow(WooMappingException::class, 'id');

    expect(ProductCategory::count())->toBe(0);
});

// ------------------------------------------------- products + variations

it('syncs products with their categories and reads variations only for variable products', function () {
    $fake = catalogFake();
    catalogSync($fake)->syncCategories();

    $result = catalogSync($fake)->syncProducts();

    expect([$result->products, $result->variations])->toBe([2, 2])
        ->and(Product::pluck('woo_product_id')->sort()->values()->all())->toBe([101, 103])
        ->and(Product::where('woo_product_id', 103)->sole()->categories()->pluck('woo_category_id')->sort()->values()->all())->toBe([31, 32])
        ->and(Product::where('woo_product_id', 101)->sole()->categories()->pluck('woo_category_id')->all())->toBe([31])
        ->and(ProductVariation::count())->toBe(2)
        ->and(ProductVariation::pluck('product_id')->unique()->all())->toBe([Product::where('woo_product_id', 103)->sole()->id])
        ->and(ProductVariation::where('woo_variation_id', 1031)->sole()->sku)->toBe('SYN-SHIRT-103-S')
        ->and(ProductVariation::where('woo_variation_id', 1031)->sole()->getAttribute('attributes'))->toEqual(['سایز' => 'S', 'رنگ' => 'مشکی'])
        ->and(ProductVariation::where('woo_variation_id', 1032)->sole()->sku)->toBeNull()
        ->and(endpointsRequested($fake))->toBe(['products/categories#1', 'products#1', 'products/103/variations#1']);
    Http::assertNothingSent();
});

it('persists the recorded product\'s own sku/price (P6 decision: products.sku/price is not PRD §09\'s literal schema)', function () {
    $fake = catalogFake();
    catalogSync($fake)->syncCategories();

    catalogSync($fake)->syncProducts();

    $tee = Product::where('woo_product_id', 101)->sole();
    expect($tee->sku)->toBe('SYN-TEE-001')->and($tee->price)->toBe(403880)->and(is_int($tee->price))->toBeTrue();
});

it('keeps the product\'s Woo created date and stamps a local synced_at', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));
    $fake = catalogFake();
    catalogSync($fake)->syncCategories();

    catalogSync($fake)->syncProducts();

    $tee = Product::where('woo_product_id', 101)->sole();
    expect($tee->created_at_woo?->utc()->format('Y-m-d H:i:s'))->toBe('2026-01-05 06:00:00')
        ->and($tee->synced_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-01 10:00:00');
});

it('is idempotent end to end: a second run adds no rows and keeps every local id', function () {
    $fake = catalogFake();
    $sync = catalogSync($fake);
    $sync->syncCategories();
    $sync->syncProducts();
    $snapshot = [Product::orderBy('id')->pluck('id')->all(), ProductVariation::orderBy('id')->pluck('id')->all(), DB::table('product_category_product')->count()];

    $sync->syncCategories();
    $second = $sync->syncProducts();

    expect([Product::orderBy('id')->pluck('id')->all(), ProductVariation::orderBy('id')->pluck('id')->all(), DB::table('product_category_product')->count()])->toBe($snapshot)
        ->and([$second->products, $second->variations])->toBe([2, 2]);
});

it('still syncs products when their categories were not synced yet, linking none and warning', function () {
    catalogSync(catalogFake())->syncProducts();

    expect(Product::count())->toBe(2)
        ->and(DB::table('product_category_product')->count())->toBe(0)
        ->and(array_filter($GLOBALS['sync_logs'], fn (array $l) => $l['level'] === 'warning'))->not->toBeEmpty();
});

it('does not fetch variations for a product type Woo does not call variable, and maps the plugin type instead of rejecting it', function () {
    $plugin = WooPayloads::set(WooPayloads::items('products')[0], 'type', 'bundle');
    $fake = catalogFake([$plugin]);

    $result = catalogSync($fake)->syncProducts();

    expect($result->products)->toBe(1)->and(Product::sole()->type->value)->toBe('simple')
        ->and(endpointsRequested($fake))->toBe(['products#1']);
});

it('reports an empty catalog as zero without touching the database', function () {
    $fake = catalogFake([]);

    $result = catalogSync($fake)->syncProducts();

    expect([$result->products, $result->variations])->toBe([0, 0])->and(Product::count())->toBe(0);
});

// --------------------------------------------------------------- failures

it('fails loudly on a malformed product payload, keeping the products already committed and writing nothing of it', function () {
    $bad = WooPayloads::without(variableProduct103(), 'name');
    $fake = catalogFake([WooPayloads::items('products')[0], $bad]);

    expect(fn () => catalogSync($fake)->syncProducts())->toThrow(WooMappingException::class, 'name');

    expect(Product::pluck('woo_product_id')->all())->toBe([101]);
});

it('never persists a variable product whose variations could not be read', function () {
    $terminal = new WooFixture('products/103/variations', 1, [], 401, [], ['code' => 'woocommerce_rest_cannot_view', 'message' => 'no']);
    $fake = catalogFake(extra: [$terminal], withVariations: false);

    expect(fn () => catalogSync($fake)->syncProducts())->toThrow(WooRequestException::class);

    expect(Product::pluck('woo_product_id')->all())->toBe([101])
        ->and(ProductVariation::count())->toBe(0);
});

it('fails loudly, corrupting nothing, when a Woo SKU is already taken by another variation', function () {
    $other = Product::factory()->create(['woo_product_id' => 900]);
    $owner = ProductVariation::factory()->create(['product_id' => $other->id, 'woo_variation_id' => 9001, 'sku' => 'SYN-SHIRT-103-S', 'price' => 42]);

    expect(fn () => catalogSync(catalogFake())->syncProducts())->toThrow(CatalogIntegrityException::class);

    expect($owner->fresh()->only(['sku', 'price', 'product_id']))->toBe(['sku' => 'SYN-SHIRT-103-S', 'price' => 42, 'product_id' => $other->id])
        ->and(Product::where('woo_product_id', 103)->exists())->toBeFalse()
        ->and(ProductVariation::count())->toBe(1);
});

it('reads Woo only through the WooClient contract', function () {
    $constructor = (new ReflectionClass(CatalogSyncService::class))->getConstructor();
    $types = array_map(fn (ReflectionParameter $p) => (string) $p->getType(), $constructor?->getParameters() ?? []);

    expect($types)->toContain(WooClient::class);
});
