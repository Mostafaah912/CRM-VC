<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Sync\Enums\CatalogSyncPhase;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Services\CatalogSyncCheckpoint;
use App\Modules\Sync\Services\CatalogSyncService;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooFixtures;
use Tests\Support\WooPayloads;

/*
| P8-03g — the catalog sync is INCREMENTAL and RESUMABLE.
|  - First run (no cursor), `full`, or a cursor older than 14 days: a FULL read. Otherwise only products modified after
|    `cursor - woo.overlap_minutes` (modified_after, dates_are_gmt), ordered by id, asking Woo for the mapped fields only.
|  - The cursor moves ONLY when a run completes (CLAUDE.md sync rules) and lands on the instant the run FIRST started, frozen across a resume.
|  - Every fully written products page is checkpointed in sync_cursors.checkpoint: a failed or killed run continues at the page it reached
|    (same window, categories not re-read) when the checkpoint is younger than 24 h; an older one, or a `full` request over an incremental
|    checkpoint, starts over.
| FakeWooClient ignores the shaping parameters (_fields, orderby, order, modified_after, dates_are_gmt) when matching a fixture, and records them.
*/

const INC_NOW = '2026-10-25 08:00:00';

beforeEach(function () {
    config(['logging.default' => 'null', 'woo.overlap_minutes' => 10]);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse(INC_NOW, 'UTC'));
});

function incProduct(int $id): array
{
    $raw = WooPayloads::set(WooPayloads::items('products')[0], 'id', $id);
    $raw = WooPayloads::set($raw, 'sku', "INC-{$id}");

    return WooPayloads::set($raw, 'slug', "inc-{$id}");
}

/**
 * @param  list<list<array<string, mixed>>>  $pages  products, one list per page
 * @param  int|null  $failOnPage  that products page answers HTTP 500 (retries exhausted)
 */
function incFake(array $pages, ?int $failOnPage = null): FakeWooClient
{
    $fixtures = array_values(array_filter(WooFixtures::all(), fn (WooFixture $f) => $f->endpoint === 'products/categories'));
    $headers = ['X-WP-TotalPages' => (string) count($pages), 'X-WP-Total' => (string) array_sum(array_map('count', $pages))];

    foreach ($pages as $index => $items) {
        $page = $index + 1;
        $fixtures[] = $page === $failOnPage
            ? new WooFixture('products', $page, [], 500, [], ['code' => 'internal_server_error'], attempts: 4)
            : new WooFixture('products', $page, [], 200, $headers, $items);
    }

    return new FakeWooClient($fixtures);
}

function incRun(FakeWooClient $woo, bool $full = false): void
{
    app()->instance(WooClient::class, $woo);
    app(CatalogSyncService::class)->syncAll($full);
}

/** @return list<array{endpoint: string, page: int, query: array<string, scalar>}> */
function incRequests(FakeWooClient $woo, string $endpoint): array
{
    return array_values(array_filter(array_map(
        fn (array $r) => ['endpoint' => $r['endpoint'], 'page' => $r['page'], 'query' => $r['query']],
        $woo->requests(),
    ), fn (array $r) => $r['endpoint'] === $endpoint));
}

function incCursor(): ?SyncCursor
{
    return SyncCursor::query()->find('catalog');
}

function incSeedCursor(string $value): void
{
    DB::table('sync_cursors')->insert(['entity' => 'catalog', 'cursor_value' => $value, 'last_status' => 'completed', 'consecutive_failures' => 0]);
}

// ================================================================== which mode

it('makes the first run a FULL read: no modified_after, ordered by id, mapped fields only; the cursor lands on the frozen start and the checkpoint is cleared', function () {
    $woo = incFake([[incProduct(201), incProduct(202)]]);

    incRun($woo);

    $query = incRequests($woo, 'products')[0]['query'];
    expect($query)->not->toHaveKey('modified_after')->and($query)->not->toHaveKey('dates_are_gmt')
        ->and($query['orderby'])->toBe('id')->and($query['order'])->toBe('asc')
        ->and($query['_fields'])->toBe(CatalogSyncService::PRODUCT_FIELDS)
        ->and(incRequests($woo, 'products/categories')[0]['query']['_fields'])->toBe(CatalogSyncService::CATEGORY_FIELDS);

    $cursor = incCursor();
    expect(Product::count())->toBe(2)
        ->and($cursor->cursor_value->utc()->format('Y-m-d H:i:s'))->toBe(INC_NOW)
        ->and($cursor->last_status->value)->toBe('completed')
        ->and($cursor->checkpoint)->toBeNull();
});

it('makes the next run INCREMENTAL: modified_after = the cursor minus the overlap, then moves the cursor to the new start', function () {
    incSeedCursor('2026-10-24 22:00:00+00');
    $woo = incFake([[incProduct(203)]]);

    incRun($woo);

    $query = incRequests($woo, 'products')[0]['query'];
    expect($query['modified_after'])->toBe('2026-10-24T21:50:00')->and($query['dates_are_gmt'])->toBe('true')
        ->and(incCursor()->cursor_value->utc()->format('Y-m-d H:i:s'))->toBe(INC_NOW);
});

it('reads everything when a full rebuild is asked for, even with a fresh cursor', function () {
    incSeedCursor('2026-10-24 22:00:00+00');
    $woo = incFake([[incProduct(204)]]);

    incRun($woo, full: true);

    expect(incRequests($woo, 'products')[0]['query'])->not->toHaveKey('modified_after');
});

it('does not trust an incremental window older than 14 days: a full read', function () {
    incSeedCursor('2026-10-10 00:00:00+00');   // 15 days before INC_NOW
    $woo = incFake([[incProduct(205)]]);

    incRun($woo);

    expect(incRequests($woo, 'products')[0]['query'])->not->toHaveKey('modified_after');
});

it('asks for the variations of a variable product with the mapped fields only', function () {
    $product = WooPayloads::set(WooPayloads::items('products')[1], 'id', 103);
    $product = WooPayloads::set($product, 'sku', 'SYN-SHIRT-103');
    $product = WooPayloads::set($product, 'categories', [['id' => 31, 'name' => 'پوشاک آزمایشی']]);
    $fixtures = array_values(array_filter(WooFixtures::all(), fn (WooFixture $f) => in_array($f->endpoint, ['products/categories', 'products/103/variations'], true)));
    $fixtures[] = new WooFixture('products', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1'], [$product]);
    $woo = new FakeWooClient($fixtures);

    incRun($woo);

    expect(incRequests($woo, 'products/103/variations')[0]['query']['_fields'])->toBe(CatalogSyncService::VARIATION_FIELDS);
});

// ================================================================== resume

it('keeps the cursor and a page checkpoint when a run fails, then resumes at that page over the SAME window, without re-reading the categories', function () {
    $failing = incFake([[incProduct(211)], [incProduct(212)], [incProduct(213)]], failOnPage: 2);

    expect(fn () => incRun($failing))->toThrow(WooRequestException::class);

    $cursor = incCursor();
    expect(Product::pluck('woo_product_id')->all())->toBe([211])
        ->and($cursor->cursor_value)->toBeNull()                         // the cursor never moves on a failed run
        ->and($cursor->last_status->value)->toBe('failed')
        ->and($cursor->checkpoint)->toMatchArray(['mode' => 'full', 'phase' => 'products', 'next_page' => 2, 'cursor_to' => '2026-10-25T08:00:00Z']);

    $this->travelTo(CarbonImmutable::parse(INC_NOW, 'UTC')->addHour());
    $resumed = incFake([[incProduct(211)], [incProduct(212)], [incProduct(213)]]);

    incRun($resumed);

    expect(array_column(incRequests($resumed, 'products'), 'page'))->toBe([2, 3])        // page 1 is not read again
        ->and(incRequests($resumed, 'products/categories'))->toBe([])                    // nor the categories
        ->and(Product::count())->toBe(3)
        ->and(incCursor()->cursor_value->utc()->format('Y-m-d H:i:s'))->toBe(INC_NOW)    // the ORIGINAL start, not the resumed run's
        ->and(incCursor()->checkpoint)->toBeNull()
        ->and(incCursor()->last_status->value)->toBe('completed');
});

it('does not resume a checkpoint older than 24 hours: it starts over from the first page', function () {
    expect(fn () => incRun(incFake([[incProduct(221)], [incProduct(222)]], failOnPage: 2)))->toThrow(WooRequestException::class);

    $this->travelTo(CarbonImmutable::parse(INC_NOW, 'UTC')->addHours(25));
    $fresh = incFake([[incProduct(221)], [incProduct(222)]]);

    incRun($fresh);

    expect(array_column(incRequests($fresh, 'products'), 'page'))->toBe([1, 2])
        ->and(incRequests($fresh, 'products/categories'))->not->toBe([])
        ->and(incCursor()->cursor_value->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 09:00:00');
});

it('starts a fresh FULL run when a full rebuild is asked for over a saved INCREMENTAL checkpoint', function () {
    incSeedCursor('2026-10-24 22:00:00+00');
    expect(fn () => incRun(incFake([[incProduct(231)], [incProduct(232)]], failOnPage: 2)))->toThrow(WooRequestException::class);
    expect(incCursor()->checkpoint['mode'])->toBe('incremental');

    $full = incFake([[incProduct(231)], [incProduct(232)]]);
    incRun($full, full: true);

    expect(array_column(incRequests($full, 'products'), 'page'))->toBe([1, 2])
        ->and(incRequests($full, 'products')[0]['query'])->not->toHaveKey('modified_after');
});

it('resumes an INCREMENTAL run over its original modified_after, not a new one', function () {
    incSeedCursor('2026-10-24 22:00:00+00');
    expect(fn () => incRun(incFake([[incProduct(241)], [incProduct(242)]], failOnPage: 2)))->toThrow(WooRequestException::class);

    $this->travelTo(CarbonImmutable::parse(INC_NOW, 'UTC')->addHour());
    $resumed = incFake([[incProduct(241)], [incProduct(242)]]);
    incRun($resumed);

    expect(incRequests($resumed, 'products')[0]['query']['modified_after'])->toBe('2026-10-24T21:50:00')   // not 22:50 - 10 min
        ->and(incCursor()->cursor_value->utc()->format('Y-m-d H:i:s'))->toBe(INC_NOW);
});

it('treats an unreadable checkpoint as no checkpoint: the run starts over', function () {
    DB::table('sync_cursors')->insert(['entity' => 'catalog', 'cursor_value' => null, 'last_status' => 'failed', 'consecutive_failures' => 0, 'checkpoint' => json_encode(['mode' => 'nonsense'])]);
    $woo = incFake([[incProduct(251)]]);

    incRun($woo);

    expect(array_column(incRequests($woo, 'products'), 'page'))->toBe([1])->and(incCursor()->checkpoint)->toBeNull();
});

// ================================================================== failure bookkeeping

it('marks the cursor failed but keeps the checkpoint when the job\'s failed() path runs (also after a timeout kill)', function () {
    $checkpoint = app(CatalogSyncCheckpoint::class);
    $run = $checkpoint->begin();
    $checkpoint->advance($run->at(CatalogSyncPhase::Products, 3));

    $checkpoint->recordFailure(new RuntimeException('killed'));

    $cursor = incCursor();
    expect($cursor->last_status->value)->toBe('failed')->and($cursor->checkpoint['next_page'])->toBe(3)->and($cursor->cursor_value)->toBeNull();
});
