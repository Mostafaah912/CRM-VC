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
use Illuminate\Queue\TimeoutExceededException;
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
| P8-03g — the catalog runs on its OWN schedule, out of the nightly chain, and fails LOUDLY on its own: the job throws, its failed() hook (which
| the worker also calls when it KILLS the job at its timeout — the case no fail-soft could ever catch) raises ONE SyncFailure alert and marks
| the cursor failed. The previous run's mirror stays; the next run resumes from the checkpoint.
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

it('gives the catalog 1500 seconds — a full read is ~900 s (311 sequential requests at 2.3-4 s) — and stays unique above that', function () {
    $job = new CatalogSyncJob;

    expect($job->timeout)->toBe(1500)->and($job->timeout)->toBeGreaterThan(911 * 1.5)->and($job->uniqueFor)->toBeGreaterThan($job->timeout)->and($job->tries)->toBe(1);
});

it('keeps job timeout < Horizon supervisor timeout < queue retry_after, each strictly: the worker timeout (which calls failed()) always fires first, never the supervisor, never a re-reserve that runs the catalog twice', function () {
    $job = new CatalogSyncJob;
    $horizon = (int) config('horizon.defaults.supervisor-1.timeout');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($job->timeout)->toBeLessThan($horizon)
        ->and($horizon)->toBeLessThan($retryAfter)
        ->and($retryAfter)->toBeGreaterThanOrEqual($job->timeout + 30);
});

it('throws on a Woo failure (it is no longer a fail-soft chain step) and raises exactly one CatalogSyncFailed with the class name only', function () {
    Event::fake([CatalogSyncFailed::class]);
    app()->instance(WooClient::class, failingWoo());

    expect(fn () => CatalogSyncJob::dispatchSync())->toThrow(WooRequestException::class);

    Event::assertDispatchedTimes(CatalogSyncFailed::class, 1);
    Event::assertDispatched(CatalogSyncFailed::class, fn ($e) => $e->exception === WooRequestException::class);
});

it('raises the alert from failed() directly too — the path a worker timeout kill takes, where handle() never gets to catch anything', function () {
    Event::fake([CatalogSyncFailed::class]);

    (new CatalogSyncJob)->failed(new TimeoutExceededException('CatalogSyncJob has timed out.'));

    Event::assertDispatched(CatalogSyncFailed::class, fn ($e) => $e->exception === TimeoutExceededException::class);
});

it('turns CatalogSyncFailed into one SyncFailure alert, in its own dedupe window', function () {
    app()->instance(WooClient::class, failingWoo());

    foreach ([1, 2] as $_) {
        try {
            CatalogSyncJob::dispatchSync();
        } catch (WooRequestException) {
        }
    }
    $alerts = DB::table('audit_logs')->where('action', 'alert.critical')->pluck('after')->map(fn ($a) => json_decode($a, true));

    expect($alerts)->toHaveCount(1)->and($alerts[0]['kind'])->toBe('sync_failure')->and($alerts[0]['context']['entity'])->toBe('catalog')->and($alerts[0]['message'])->toContain('WooRequestException')
        ->and($alerts[0]['message'])->not->toContain('connection failure')   // class name only, never the Woo/transport text
        ->and($alerts[0]['message'])->not->toContain('nightly chain');       // the catalog is not a chain step any more
});
