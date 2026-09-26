<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditLog;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Refund;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncLogLevel;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Exceptions\WooFixtureNotFoundException;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Jobs\SyncPageJob;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Models\SyncLog;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\SyncService;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooFixture;
use App\Modules\Sync\Support\WooPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WooFixtures;
use Tests\Support\WooPayloads;

/*
| P2-08 — SyncService: one run = one sync_jobs row with a FROZEN window (cursor_to = now() at start, never moved);
| pages are synced one SyncPageJob at a time, each queuing the next while Woo says more follow; the stored cursor
| advances to the frozen cursor_to ONLY when the run reaches `completed` (CLAUDE.md §5). Woo is the FakeWooClient
| replaying recorded fixtures — never the network. "Now" is pinned so every timestamp below is exact.
*/

const RUN_NOW = '2026-06-01 12:00:00';

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse(RUN_NOW, 'UTC'));
});

function runService(?WooClient $fake = null): SyncService
{
    app()->instance(WooClient::class, $fake ?? WooFixtures::client());

    return app(SyncService::class);
}

/** A run left in $status, started $ageSeconds ago. */
function runStale(int $ageSeconds, SyncStatus $status = SyncStatus::Running): SyncJob
{
    return SyncJob::create([
        'entity' => SyncEntity::Orders, 'mode' => SyncMode::Incremental, 'status' => $status,
        'cursor_from' => null, 'cursor_to' => CarbonImmutable::now('UTC')->subSeconds($ageSeconds),
        'started_at' => CarbonImmutable::now('UTC')->subSeconds($ageSeconds),
    ]);
}

function runCursor(?string $value, SyncStatus $status = SyncStatus::Completed, int $failures = 0): SyncCursor
{
    return SyncCursor::create(['entity' => 'orders', 'cursor_value' => $value, 'last_status' => $status, 'consecutive_failures' => $failures]);
}

/** @return Collection<int, SyncLog> */
function runLogs(SyncJob $run, ?string $message = null): Collection
{
    return SyncLog::where('sync_job_id', $run->id)
        ->when($message !== null, fn ($q) => $q->where('message', $message))
        ->orderBy('id')->get();
}

function runIso(?CarbonImmutable $moment): ?string
{
    return $moment?->utc()->format('Y-m-d\TH:i:s');
}

/** The recorded first orders page only — page 2 is not recorded, so asking for it fails. */
function runClientFirstPageOnly(): FakeWooClient
{
    return new FakeWooClient(array_values(array_filter(
        WooFixtures::all(),
        fn (WooFixture $f) => $f->endpoint === 'orders' && $f->page === 1 && $f->query === [],
    )));
}

/** One page of the given order payloads, plus the recorded refunds of order 5001. */
function runClientWithRefunds(array $orderPayloads, bool $withRefundFixture = true): FakeWooClient
{
    $fixtures = [new WooFixture('orders', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($orderPayloads)], $orderPayloads)];

    if ($withRefundFixture) {
        array_push($fixtures, ...array_values(array_filter(WooFixtures::all(), fn (WooFixture $f) => $f->endpoint === 'orders/5001/refunds')));
    }

    return new FakeWooClient($fixtures);
}

function runOrderListingRefunds(int $recordedIndex, int $count): array
{
    $summaries = $count === 0 ? [] : array_map(fn (int $n): array => ['id' => 7000 + $n, 'reason' => '', 'total' => '-1000'], range(1, $count));

    return WooPayloads::set(WooPayloads::items('orders')[$recordedIndex], 'refunds', $summaries);
}

// ================================================================== starting a run

it('starts a run: cursor_to frozen at now, no lower bound on the very first sync, page 1 queued on the sync queue', function () {
    Queue::fake();

    $run = runService()->run(SyncEntity::Orders);

    expect($run)->toBeInstanceOf(SyncJob::class)
        ->and($run->entity)->toBe(SyncEntity::Orders)
        ->and($run->mode)->toBe(SyncMode::Incremental)
        ->and($run->status)->toBe(SyncStatus::Running)
        ->and($run->cursor_from)->toBeNull()
        ->and(runIso($run->cursor_to))->toBe('2026-06-01T12:00:00')
        ->and([$run->pages_processed, $run->records_processed, $run->records_failed])->toBe([0, 0, 0])
        ->and($run->finished_at)->toBeNull()
        ->and($run->error)->toBeNull();

    $cursor = SyncCursor::findOrFail('orders');
    expect($cursor->cursor_value)->toBeNull()
        ->and($cursor->last_status)->toBe(SyncStatus::Running)
        ->and($cursor->consecutive_failures)->toBe(0)
        ->and(runIso($cursor->last_run_at))->toBe('2026-06-01T12:00:00');
    Queue::assertPushed(SyncPageJob::class, 1);
    Queue::assertPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->syncJobId === $run->id && $job->page === 1 && $job->queue === 'sync');
});

it('derives cursor_from from the stored cursor minus the configured overlap', function (int $overlap, string $expected) {
    Queue::fake();
    config(['woo.overlap_minutes' => $overlap]);
    runCursor('2026-06-01 11:30:00+00');

    $run = runService()->run(SyncEntity::Orders);

    expect(runIso($run->cursor_from))->toBe($expected)
        ->and(runIso($run->cursor_to))->toBe('2026-06-01T12:00:00');
})->with([
    'the default overlap' => [10, '2026-06-01T11:20:00'],
    'a configured overlap' => [25, '2026-06-01T11:05:00'],
]);

it('does not move the stored cursor when a run starts', function () {
    Queue::fake();
    runCursor('2026-06-01 11:30:00+00');

    runService()->run(SyncEntity::Orders);

    expect(runIso(SyncCursor::findOrFail('orders')->cursor_value))->toBe('2026-06-01T11:30:00');
});

it('writes a "Run started" log with the frozen window', function () {
    Queue::fake();
    runCursor('2026-06-01 11:30:00+00');

    $run = runService()->run(SyncEntity::Orders);

    $log = runLogs($run, 'Run started')->sole();
    expect($log->level)->toBe(SyncLogLevel::Info)
        ->and($log->context)->toEqual(['entity' => 'orders', 'mode' => 'incremental', 'cursor_from' => '2026-06-01T11:20:00', 'cursor_to' => '2026-06-01T12:00:00']);
});

it('still rejects the webhook mode: webhook deliveries start incremental runs (P2-09)', function () {
    Queue::fake();

    expect(fn () => runService()->run(SyncEntity::Orders, SyncMode::Webhook))->toThrow(InvalidArgumentException::class);

    expect(SyncJob::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects SyncEntity::Catalog: its window/cursor machinery is Orders-shaped only, catalog runs through CatalogSyncJob', function () {
    Queue::fake();

    expect(fn () => runService()->run(SyncEntity::Catalog))->toThrow(InvalidArgumentException::class);

    expect(SyncJob::count())->toBe(0);
    Queue::assertNothingPushed();
});

// ================================================================== overlapping and stale runs (S-a)

it('refuses to start while a run for the entity is younger than 3600 seconds, changing nothing', function (int $age) {
    Queue::fake();
    $fresh = runStale($age);

    $result = runService()->run(SyncEntity::Orders);

    expect($result)->toBeNull()
        ->and(SyncJob::count())->toBe(1)
        ->and($fresh->fresh()->status)->toBe(SyncStatus::Running)
        ->and($fresh->fresh()->error)->toBeNull();
    Queue::assertNothingPushed();
})->with([0, 60, 3599]);

it('abandons a run that is 3600 seconds old or older: failed with reason "abandoned", counted, cursor untouched — then starts a new run', function (int $age) {
    Queue::fake();
    runCursor('2026-06-01 09:00:00+00', SyncStatus::Running);
    $stale = runStale($age);

    $new = runService()->run(SyncEntity::Orders);

    $stale->refresh();
    expect($stale->status)->toBe(SyncStatus::Failed)
        ->and($stale->error)->toBe('abandoned')
        ->and(runIso($stale->finished_at))->toBe('2026-06-01T12:00:00');
    expect($new)->not->toBeNull()
        ->and($new->id)->not->toBe($stale->id)
        ->and($new->status)->toBe(SyncStatus::Running);
    $cursor = SyncCursor::findOrFail('orders');
    expect(runIso($cursor->cursor_value))->toBe('2026-06-01T09:00:00')
        ->and($cursor->consecutive_failures)->toBe(1)
        ->and($cursor->last_status)->toBe(SyncStatus::Running);
    Queue::assertPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->syncJobId === $new->id && $job->page === 1);
    expect(runLogs($stale, 'Run abandoned')->sole()->level)->toBe(SyncLogLevel::Warning);
})->with([3600, 3601, 86400]);

it('counts every abandoned run as a failure', function () {
    Queue::fake();
    runCursor(null, SyncStatus::Running);
    runStale(7200);
    runStale(9000);

    runService()->run(SyncEntity::Orders);

    expect(SyncCursor::findOrFail('orders')->consecutive_failures)->toBe(2)
        ->and(SyncJob::where('status', SyncStatus::Failed)->count())->toBe(2);
});

it('is not blocked by runs that already finished, however recent', function (SyncStatus $finished) {
    Queue::fake();
    runStale(10, $finished);

    expect(runService()->run(SyncEntity::Orders))->not->toBeNull();
})->with([SyncStatus::Completed, SyncStatus::Failed]);

// ================================================================== a page of a run

it('syncs one page, logs it, records progress and queues the next page — without touching the cursor', function () {
    Queue::fake();
    $service = runService();
    $run = $service->run(SyncEntity::Orders);

    $service->runPage($run->id, 1);

    $run->refresh();
    expect($run->status)->toBe(SyncStatus::Running)
        ->and([$run->pages_processed, $run->records_processed])->toBe([1, 2])
        ->and($run->finished_at)->toBeNull()
        ->and(Order::count())->toBe(2)
        ->and(SyncCursor::findOrFail('orders')->cursor_value)->toBeNull();
    Queue::assertPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->syncJobId === $run->id && $job->page === 2 && $job->queue === 'sync');

    $log = runLogs($run, 'Page synced')->sole();
    expect($log->level)->toBe(SyncLogLevel::Info)
        ->and($log->context)->toEqual(['page' => 1, 'orders' => 2, 'items' => 2, 'refund_orders' => 0, 'refunds' => 0]);
});

it('hands the run\'s frozen window to Woo on every page, even hours later', function () {
    Queue::fake();
    runCursor('2026-06-01 11:30:00+00');
    $fake = WooFixtures::client();
    $service = runService($fake);
    $run = $service->run(SyncEntity::Orders);

    $this->travel(3)->hours();
    $service->runPage($run->id, 1);
    $this->travel(2)->hours();
    $service->runPage($run->id, 2);

    $windows = array_map(fn (array $r) => $r['window'], $fake->requests());
    expect($windows)->toHaveCount(2);

    foreach ($windows as $window) {
        expect($window)->toBeInstanceOf(SyncWindow::class)
            ->and(runIso($window->modifiedAfter))->toBe('2026-06-01T11:20:00')
            ->and(runIso($window->modifiedBefore))->toBe('2026-06-01T12:00:00');
    }
});

it('completes the run on the last page and ONLY then advances the cursor — to the frozen cursor_to, not to now', function () {
    Queue::fake();
    $service = runService();
    $run = $service->run(SyncEntity::Orders);

    $service->runPage($run->id, 1);
    expect(SyncCursor::findOrFail('orders')->cursor_value)->toBeNull();

    $this->travel(2)->hours();
    $service->runPage($run->id, 2);

    $run->refresh();
    $cursor = SyncCursor::findOrFail('orders');
    expect($run->status)->toBe(SyncStatus::Completed)
        ->and(runIso($run->finished_at))->toBe('2026-06-01T14:00:00')
        ->and([$run->pages_processed, $run->records_processed, $run->records_failed])->toBe([2, 3, 0])
        ->and(runIso($cursor->cursor_value))->toBe('2026-06-01T12:00:00')
        ->and($cursor->last_status)->toBe(SyncStatus::Completed)
        ->and($cursor->consecutive_failures)->toBe(0)
        ->and(Order::count())->toBe(3);
    Queue::assertPushed(SyncPageJob::class, 2); // page 1 by run(), page 2 by page 1 — nothing after the last page
    Queue::assertNotPushed(SyncPageJob::class, fn (SyncPageJob $job) => $job->page > 2);
    expect(runLogs($run, 'Run completed')->sole()->level)->toBe(SyncLogLevel::Info);
});

it('recomputes the run counters from its page logs instead of incrementing them', function () {
    Queue::fake();
    $service = runService();
    $run = $service->run(SyncEntity::Orders);
    $service->runPage($run->id, 1);
    SyncJob::whereKey($run->id)->update(['pages_processed' => 500, 'records_processed' => 500]);

    $service->runPage($run->id, 2);

    expect($run->fresh()->only(['pages_processed', 'records_processed']))->toBe(['pages_processed' => 2, 'records_processed' => 3]);
});

it('is idempotent: a second run over the same Woo data changes nothing and moves the cursor on', function () {
    Queue::fake();
    $service = runService();
    $first = $service->run(SyncEntity::Orders);
    $service->runPage($first->id, 1);
    $service->runPage($first->id, 2);
    $state = fn () => [
        Order::orderBy('woo_order_id')->get()->map->only(['id', 'woo_order_id', 'status', 'total'])->all(),
        OrderItem::orderBy('order_id')->orderBy('woo_item_id')->get()->map->only(['woo_item_id', 'qty', 'line_total'])->all(),
    ];
    $stateAfterFirst = $state();

    $this->travel(30)->minutes();
    $second = $service->run(SyncEntity::Orders);
    $service->runPage($second->id, 1);
    $service->runPage($second->id, 2);

    expect($state())->toEqual($stateAfterFirst)
        ->and(runIso($second->fresh()->cursor_from))->toBe('2026-06-01T11:50:00')
        ->and(runIso(SyncCursor::findOrFail('orders')->cursor_value))->toBe('2026-06-01T12:30:00')
        ->and($second->fresh()->status)->toBe(SyncStatus::Completed);
});

it('does nothing for a page of a run that is no longer running', function (SyncStatus $status) {
    $fake = WooFixtures::client();
    runCursor('2026-06-01 09:00:00+00');
    $run = runStale(60, $status);

    runService($fake)->runPage($run->id, 1);

    expect($fake->requests())->toBe([])
        ->and(Order::count())->toBe(0)
        ->and($run->fresh()->status)->toBe($status)
        ->and(runIso(SyncCursor::findOrFail('orders')->cursor_value))->toBe('2026-06-01T09:00:00');
})->with([SyncStatus::Failed, SyncStatus::Completed]);

it('refuses a page of a run that does not exist', function () {
    expect(fn () => runService()->runPage(987654, 1))->toThrow(ModelNotFoundException::class);
});

it('cannot complete, or advance the cursor for, a run that was abandoned while its last page was being synced', function () {
    Queue::fake();
    runCursor('2026-06-01 09:00:00+00');
    $service = runService();
    $run = $service->run(SyncEntity::Orders);
    Order::saved(fn () => SyncJob::whereKey($run->id)->update(['status' => SyncStatus::Failed->value, 'error' => 'abandoned']));

    $service->runPage($run->id, 2);

    $run->refresh();
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toBe('abandoned')
        ->and(runIso(SyncCursor::findOrFail('orders')->cursor_value))->toBe('2026-06-01T09:00:00');
});

it('records nothing and queues no next page for a run abandoned while its page was being synced', function () {
    Queue::fake();
    $service = runService();
    $run = $service->run(SyncEntity::Orders);
    Order::saved(fn () => SyncJob::whereKey($run->id)->update(['status' => SyncStatus::Failed->value, 'error' => 'abandoned']));

    $service->runPage($run->id, 1);

    expect(runLogs($run, 'Page synced'))->toHaveCount(0)
        ->and($run->fresh()->pages_processed)->toBe(0)
        ->and($run->fresh()->status)->toBe(SyncStatus::Failed);
    Queue::assertPushed(SyncPageJob::class, 1); // only page 1, queued by run()
});

it('cannot complete a run that was abandoned after its last page was recorded but before it completed', function () {
    Queue::fake();
    runCursor('2026-06-01 09:00:00+00');
    $service = runService();
    $run = $service->run(SyncEntity::Orders);
    SyncLog::created(function (SyncLog $log) use ($run) {
        if ($log->message === 'Page synced') {
            SyncJob::whereKey($run->id)->update(['status' => SyncStatus::Failed->value, 'error' => 'abandoned']);
        }
    });

    $service->runPage($run->id, 2);

    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and($run->fresh()->error)->toBe('abandoned')
        ->and(runIso(SyncCursor::findOrFail('orders')->cursor_value))->toBe('2026-06-01T09:00:00');
});

// ================================================================== failure: cursor unchanged, run failed

it('fails the run on a Woo failure: cursor unchanged, failure counted, error stored, nothing queued — and the exception still surfaces', function () {
    Queue::fake();
    runCursor('2026-06-01 10:00:00+00');
    $service = runService(new FakeWooClient([new WooFixture('orders', 1, [], 503, [], ['code' => 'busy'], attempts: 5)]));
    $run = $service->run(SyncEntity::Orders);

    expect(fn () => $service->runPage($run->id, 1))->toThrow(WooRequestException::class);

    $run->refresh();
    $cursor = SyncCursor::findOrFail('orders');
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('WooRequestException')->toContain('HTTP 503')
        ->and(runIso($run->finished_at))->toBe('2026-06-01T12:00:00')
        ->and(runIso($cursor->cursor_value))->toBe('2026-06-01T10:00:00')
        ->and($cursor->last_status)->toBe(SyncStatus::Failed)
        ->and($cursor->consecutive_failures)->toBe(1);
    Queue::assertPushed(SyncPageJob::class, 1); // only the page-1 job run() queued
    $log = runLogs($run)->last();
    expect($log->level)->toBe(SyncLogLevel::Error)
        ->and($log->message)->toStartWith('Run failed on page 1')
        ->and($log->context)->toEqual(['page' => 1, 'exception' => 'WooRequestException']);
});

it('keeps what earlier pages committed when a later page fails, and still leaves the cursor alone', function () {
    Queue::fake();
    $service = runService(runClientFirstPageOnly());
    $run = $service->run(SyncEntity::Orders);
    $service->runPage($run->id, 1);

    expect(fn () => $service->runPage($run->id, 2))->toThrow(WooFixtureNotFoundException::class);

    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and(Order::pluck('woo_order_id')->sort()->values()->all())->toBe([5001, 5002])
        ->and(SyncCursor::findOrFail('orders')->cursor_value)->toBeNull();
});

it('fails the run on a malformed order, without the offending value in any stored text', function () {
    Queue::fake();
    $bad = WooPayloads::set(WooPayloads::items('orders')[0], 'total', '09121234567-not-money');
    $service = runService(runClientWithRefunds([$bad]));
    $run = $service->run(SyncEntity::Orders);

    expect(fn () => $service->runPage($run->id, 1))->toThrow(WooMappingException::class);

    $stored = json_encode([$run->fresh()->error, runLogs($run)->map->only(['message', 'context'])->all()], JSON_UNESCAPED_UNICODE);
    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and($stored)->toContain('total')->not->toContain('09121234567');
});

it('counts consecutive failures from the run history and resets them on the next completed run', function () {
    Queue::fake();
    runCursor('2026-06-01 10:00:00+00');
    $broken = new FakeWooClient([]);

    foreach ([1, 2] as $expected) {
        $service = runService($broken);
        $run = $service->run(SyncEntity::Orders);
        try {
            $service->runPage($run->id, 1);
        } catch (WooFixtureNotFoundException) {
        }
        expect(SyncCursor::findOrFail('orders')->consecutive_failures)->toBe($expected);
    }

    $service = runService();
    $run = $service->run(SyncEntity::Orders);
    $service->runPage($run->id, 1);
    $service->runPage($run->id, 2);

    $cursor = SyncCursor::findOrFail('orders');
    expect($cursor->consecutive_failures)->toBe(0)
        ->and($cursor->last_status)->toBe(SyncStatus::Completed)
        ->and(runIso($cursor->cursor_value))->toBe('2026-06-01T12:00:00');
});

it('never lets a phone number reach sync_jobs.error or sync_logs, whatever an exception says', function () {
    Queue::fake();
    $throwing = new class implements WooClient
    {
        public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
        {
            throw new RuntimeException('bad billing 09121234567 / ۰۹۱۲۳۴۵۶۷۸۹ / +98 912 123 4567 / 0912-123-4567');
        }

        public function pages(string $endpoint, ?SyncWindow $window = null, array $query = []): Generator
        {
            yield $this->page($endpoint, 1, $window, $query);
        }
    };
    $service = runService($throwing);
    $run = $service->run(SyncEntity::Orders);

    expect(fn () => $service->runPage($run->id, 1))->toThrow(RuntimeException::class);

    $stored = json_encode([$run->fresh()->error, runLogs($run)->map->only(['message', 'context'])->all()], JSON_UNESCAPED_UNICODE);
    expect($run->fresh()->error)->toContain('RuntimeException')
        ->and($stored)->not->toContain('09121234567')->not->toContain('۰۹۱۲۳۴۵۶۷۸۹')
        ->and($stored)->not->toContain('912 123 4567')->not->toContain('0912-123-4567');
});

it('caps every sync_logs message at 500 characters, so a long exception text can never break the log', function () {
    Queue::fake();
    $long = str_repeat('x', 3000);
    $throwing = new class($long) implements WooClient
    {
        public function __construct(private readonly string $text) {}

        public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
        {
            throw new RuntimeException($this->text);
        }

        public function pages(string $endpoint, ?SyncWindow $window = null, array $query = []): Generator
        {
            yield $this->page($endpoint, 1, $window, $query);
        }
    };
    $service = runService($throwing);
    $run = $service->run(SyncEntity::Orders);

    // The exact class matters: an over-long varchar would raise a QueryException, which is also a RuntimeException.
    try {
        $service->runPage($run->id, 1);
        $this->fail('Expected the page failure to surface');
    } catch (Throwable $e) {
        expect($e::class)->toBe(RuntimeException::class);
    }

    $failure = runLogs($run)->last();
    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and($failure->level)->toBe(SyncLogLevel::Error)
        ->and(mb_strlen($failure->message))->toBeGreaterThan(400)->toBeLessThanOrEqual(500)
        ->and(mb_strlen((string) $run->fresh()->error))->toBeLessThanOrEqual(2000);
});

// ================================================================== refunds ride on the orders pages (R-b)

it('re-reads the refunds of the orders whose payload lists refunds — and only those', function () {
    Queue::fake();
    $fake = runClientWithRefunds([runOrderListingRefunds(0, 2), runOrderListingRefunds(1, 0)]);
    $service = runService($fake);
    $run = $service->run(SyncEntity::Orders);

    $service->runPage($run->id, 1);

    $run->refresh();
    expect(array_column($fake->requests(), 'endpoint'))->toBe(['orders', 'orders/5001/refunds'])
        ->and(Refund::orderBy('woo_refund_id')->pluck('woo_refund_id')->all())->toBe([7001, 7002])
        ->and(Order::where('woo_order_id', 5001)->sole()->refunded_total)->toBeGreaterThan(0)
        ->and($run->status)->toBe(SyncStatus::Completed)
        ->and($run->records_processed)->toBe(4) // 2 orders + 2 refunds
        ->and(runLogs($run, 'Page synced')->sole()->context)->toEqual(['page' => 1, 'orders' => 2, 'items' => 2, 'refund_orders' => 1, 'refunds' => 2]);
});

it('re-reads the refunds of an order that holds some locally even when Woo now lists none, so a Woo deletion is mirrored away and audited', function () {
    Queue::fake();
    $first = runService(runClientWithRefunds([runOrderListingRefunds(0, 2)]));
    $run = $first->run(SyncEntity::Orders);
    $first->runPage($run->id, 1);
    expect(Refund::count())->toBe(2);

    $this->travel(20)->minutes();
    $noneNow = new FakeWooClient([
        new WooFixture('orders', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1'], [runOrderListingRefunds(0, 0)]),
        new WooFixture('orders/5001/refunds', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0'], []),
    ]);
    $second = runService($noneNow);
    $run2 = $second->run(SyncEntity::Orders);
    $second->runPage($run2->id, 1);

    expect(array_column($noneNow->requests(), 'endpoint'))->toBe(['orders', 'orders/5001/refunds'])
        ->and(Refund::count())->toBe(0)
        ->and(Order::where('woo_order_id', 5001)->sole()->only(['refunded_total', 'is_fully_refunded']))->toBe(['refunded_total' => 0, 'is_fully_refunded' => false])
        ->and(AuditLog::where('action', 'refund.deleted')->count())->toBe(2)
        ->and($run2->fresh()->status)->toBe(SyncStatus::Completed);
});

it('asks Woo for no refunds at all when no order lists or holds any', function () {
    Queue::fake();
    $fake = WooFixtures::client();
    $service = runService($fake);
    $run = $service->run(SyncEntity::Orders);

    $service->runPage($run->id, 1);
    $service->runPage($run->id, 2);

    expect(array_values(array_unique(array_column($fake->requests(), 'endpoint'))))->toBe(['orders']);
});

it('fails the run if a refund read fails, keeping the orders already stored and never advancing the cursor', function () {
    Queue::fake();
    $service = runService(runClientWithRefunds([runOrderListingRefunds(0, 1)], withRefundFixture: false));
    $run = $service->run(SyncEntity::Orders);

    expect(fn () => $service->runPage($run->id, 1))->toThrow(WooFixtureNotFoundException::class);

    expect($run->fresh()->status)->toBe(SyncStatus::Failed)
        ->and(Order::where('woo_order_id', 5001)->count())->toBe(1)
        ->and(Refund::count())->toBe(0)
        ->and(SyncCursor::findOrFail('orders')->cursor_value)->toBeNull();
});
