<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Sync\Events\CatalogSyncFailed;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooFixture;
use App\Modules\Sync\Support\WooPage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

/*
| P7-08 follow-up — the catalog is fail-soft INSIDE the nightly chain. Measured live: ~96 sequential Woo requests at ~3 s each, which a 300 s
| timeout could not hold; every timeout stopped the whole Bus::chain (09-30, 10-05, 10-18) so metrics/daily/cohort/segments never ran.
*/

function failingWoo(): WooClient
{
    return new class implements WooClient
    {
        public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
        {
            throw new WooRequestException('Woo GET failed with a connection failure or timeout.', $endpoint, null, 5, true);
        }

        public function pages(string $endpoint, ?SyncWindow $window = null, array $query = []): Generator
        {
            throw new WooRequestException('Woo GET failed with a connection failure or timeout.', $endpoint, null, 5, true);
            yield;
        }
    };
}

it('gives the catalog 1800 seconds — the live run MEASURED 911 s (310 sequential Woo requests) — and stays unique above that', function () {
    $job = new CatalogSyncJob;

    expect($job->timeout)->toBe(1800)->and($job->timeout)->toBeGreaterThan(911 * 1.5)->and($job->uniqueFor)->toBeGreaterThan($job->timeout)->and($job->tries)->toBe(1);
});

it('keeps its timeout under the queue\'s retry_after (by the project\'s 30 s margin) and within Horizon\'s supervisor timeout, so a running catalog is never re-reserved and run twice', function () {
    $job = new CatalogSyncJob;

    expect((int) config('queue.connections.redis.retry_after'))->toBeGreaterThanOrEqual($job->timeout + 30)
        ->and((int) config('horizon.defaults.supervisor-1.timeout'))->toBeGreaterThanOrEqual($job->timeout)
        ->and((int) config('horizon.defaults.supervisor-1.timeout'))->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('standalone (the default) still throws on a Woo failure, exactly as before', function () {
    app()->instance(WooClient::class, failingWoo());

    expect(fn () => CatalogSyncJob::dispatchSync())->toThrow(WooRequestException::class);
});

it('fail-soft ends normally on a Woo failure, logs only the class, and raises a SyncFailure alert for the catalog', function () {
    Event::fake([CatalogSyncFailed::class]);
    app()->instance(WooClient::class, failingWoo());

    CatalogSyncJob::dispatchSync(failSoft: true);

    Event::assertDispatched(CatalogSyncFailed::class, fn ($e) => $e->exception === WooRequestException::class);
});

it('turns CatalogSyncFailed into one SyncFailure alert, in its own dedupe window', function () {
    app()->instance(WooClient::class, failingWoo());

    CatalogSyncJob::dispatchSync(failSoft: true);
    CatalogSyncJob::dispatchSync(failSoft: true);
    $alerts = DB::table('audit_logs')->where('action', 'alert.critical')->pluck('after')->map(fn ($a) => json_decode($a, true));

    expect($alerts)->toHaveCount(1)->and($alerts[0]['kind'])->toBe('sync_failure')->and($alerts[0]['context']['entity'])->toBe('catalog')->and($alerts[0]['message'])->toContain('WooRequestException')
        ->and($alerts[0]['message'])->not->toContain('connection failure');   // class name only, never the Woo/transport text
});
