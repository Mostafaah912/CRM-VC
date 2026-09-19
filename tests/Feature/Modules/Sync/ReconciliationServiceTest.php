<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStatusMapper;
use App\Modules\Sync\Enums\ReconciliationStatus;
use App\Modules\Sync\Exceptions\ReconciliationException;
use App\Modules\Sync\Exceptions\WooCurrencyMismatchException;
use App\Modules\Sync\Exceptions\WooMappingException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Jobs\ReconcileMonthJob;
use App\Modules\Sync\Models\ReconciliationReportModel;
use App\Modules\Sync\Services\ReconciliationService;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\ReconciliationMonths;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WooOrderTotalsSimulator;

/*
| P2-11 — reconciliation of one Jalali month: Woo (through the WooClient, all pages of `orders`, never a reports endpoint)
| against the local orders table, over the same half-open window [first day 00:00 Tehran, next month's first day 00:00
| Tehran). Counts are compared over ALL statuses (X-WP-Total of the first page against COUNT(*) of non-deleted local orders);
| revenue over REALIZED statuses on both sides (config woo.realized_statuses — Woo filtered client-side from the same pages,
| local by is_realized). Green = count_diff 0 AND variance strictly under 1%. Integer Toman throughout.
|
| "Now" is 2025-01-10 (Tehran 1403-10-21): the last complete month is 1403-09, so 1403-07..1403-09 are reconcilable.
*/

const RECON_NOW = '2025-01-10 12:00:00';

// Mehr 1403 is [2024-09-21 20:30:00Z, 2024-10-21 20:30:00Z). Two orders sit exactly on the edges: the start is inside, the end is not.
const RECON_IN = [
    ['created' => '2024-09-21 20:30:00', 'status' => 'processing', 'total' => 100_000],   // the very first second
    ['created' => '2024-10-01 08:00:00', 'status' => 'completed', 'total' => 250_000],
    ['created' => '2024-10-10 12:00:00', 'status' => 'cancelled', 'total' => 70_000],
    ['created' => '2024-10-21 20:29:59', 'status' => 'pending', 'total' => 30_000],       // the very last second
];

const RECON_OUT = [
    ['created' => '2024-09-21 20:29:59', 'status' => 'completed', 'total' => 900_000],    // one second before
    ['created' => '2024-10-21 20:30:00', 'status' => 'completed', 'total' => 800_000],    // exactly the end: next month
    ['created' => '2024-11-05 00:00:00', 'status' => 'processing', 'total' => 700_000],
];

beforeEach(function () {
    config(['logging.default' => 'null', 'woo.realized_statuses' => ['processing', 'completed'], 'woo.currency' => 'IRT']);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse(RECON_NOW, 'UTC'));
    $GLOBALS['recon_customer'] = Customer::factory()->create()->id;
});

function reconService(WooClient $woo): ReconciliationService
{
    app()->instance(WooClient::class, $woo);

    return app(ReconciliationService::class);
}

/** A local order; is_realized is derived from the same config as the sync does it (OrderStatusMapper). */
function reconLocal(string $createdUtc, int $total, string $status = 'processing', bool $deleted = false): Order
{
    $order = Order::factory()->create([
        'customer_id' => $GLOBALS['recon_customer'],
        'status' => $status,
        'is_realized' => app(OrderStatusMapper::class)->isRealized($status),
        'total' => $total,
        'ordered_at' => CarbonImmutable::parse($createdUtc, 'UTC'),
    ]);

    if ($deleted) {
        $order->delete();
    }

    return $order;
}

/** @param  list<array{created: string, status: string, total: int}>  $specs */
function reconSeedLocal(array $specs): void
{
    foreach ($specs as $spec) {
        reconLocal($spec['created'], $spec['total'], $spec['status']);
    }
}

/** Both sides hold the same standard month (plus the out-of-window orders on each). */
function reconAgreeing(): WooOrderTotalsSimulator
{
    reconSeedLocal([...RECON_IN, ...RECON_OUT]);

    return new WooOrderTotalsSimulator([...RECON_IN, ...RECON_OUT]);
}

// ================================================================== the comparison

it('is green when counts and realized revenue agree, and reads the month window exactly: the first second in, the end out', function () {
    $report = reconService(reconAgreeing())->compare('1403-07');

    expect($report->jalaliMonth)->toBe('1403-07')
        ->and([$report->wooOrderCount, $report->localOrderCount, $report->countDiff])->toBe([4, 4, 0])
        ->and([$report->wooRevenue, $report->localRevenue, $report->revenueDiff])->toBe([350_000, 350_000, 0])
        ->and($report->revenueDiffPct)->toBe('0.0000')
        ->and($report->status)->toBe(ReconciliationStatus::Green)
        ->and($report->isGreen())->toBeTrue();
});

it('sums Woo revenue over the realized statuses only, from config — the statuses are not in the code', function () {
    config(['woo.realized_statuses' => ['completed']]);
    reconSeedLocal(RECON_IN);

    $report = reconService(new WooOrderTotalsSimulator(RECON_IN))->compare('1403-07');

    expect([$report->wooRevenue, $report->localRevenue])->toBe([250_000, 250_000])
        ->and($report->status)->toBe(ReconciliationStatus::Green);
});

it('notices when the realized statuses were changed after the orders were synced: Woo side follows config, local is_realized is what was stored', function () {
    reconSeedLocal(RECON_IN);          // stored with processing + completed realized: 350000
    config(['woo.realized_statuses' => ['completed']]);

    $report = reconService(new WooOrderTotalsSimulator(RECON_IN))->compare('1403-07');

    expect([$report->wooRevenue, $report->localRevenue])->toBe([250_000, 350_000])
        ->and($report->status)->toBe(ReconciliationStatus::Red);
});

it('counts every status on both sides: a cancelled or pending order is in the count and out of the revenue', function () {
    reconSeedLocal(RECON_IN);

    $report = reconService(new WooOrderTotalsSimulator(RECON_IN))->compare('1403-07');

    expect([$report->wooOrderCount, $report->localOrderCount])->toBe([4, 4])
        ->and([$report->wooRevenue, $report->localRevenue])->toBe([350_000, 350_000]);
});

it('is red on any count difference, however small the money difference', function () {
    reconSeedLocal(array_slice(RECON_IN, 0, 3)); // the local side is missing one order (a pending one: no revenue effect)

    $report = reconService(new WooOrderTotalsSimulator(RECON_IN))->compare('1403-07');

    expect([$report->wooOrderCount, $report->localOrderCount, $report->countDiff])->toBe([4, 3, 1])
        ->and($report->revenueDiff)->toBe(0)
        ->and($report->status)->toBe(ReconciliationStatus::Red);
});

it('is red when the local side has an order Woo does not, with a negative count difference', function () {
    reconSeedLocal([...RECON_IN, ['created' => '2024-10-05 10:00:00', 'status' => 'cancelled', 'total' => 1_000]]);

    $report = reconService(new WooOrderTotalsSimulator(RECON_IN))->compare('1403-07');

    expect($report->countDiff)->toBe(-1)->and($report->status)->toBe(ReconciliationStatus::Red);
});

it('draws the revenue line strictly at 1%: exactly 1% is red, one Toman under is green', function (int $local, string $pct, ReconciliationStatus $status) {
    reconLocal('2024-10-01 08:00:00', $local);
    $woo = new WooOrderTotalsSimulator([['created' => '2024-10-01 08:00:00', 'status' => 'processing', 'total' => 1_000_000]]);

    $report = reconService($woo)->compare('1403-07');

    expect($report->countDiff)->toBe(0)
        ->and($report->revenueDiffPct)->toBe($pct)
        ->and($report->status)->toBe($status);
})->with([
    'exactly 1%' => [990_000, '1.0000', ReconciliationStatus::Red],
    'one Toman under' => [990_001, '0.9999', ReconciliationStatus::Green],
    'no difference' => [1_000_000, '0.0000', ReconciliationStatus::Green],
    'local above Woo by 2%' => [1_020_000, '2.0000', ReconciliationStatus::Red],
]);

it('keeps the signed revenue difference (Woo minus local) and an absolute percentage', function () {
    reconLocal('2024-10-01 08:00:00', 1_005);
    $woo = new WooOrderTotalsSimulator([['created' => '2024-10-01 08:00:00', 'status' => 'processing', 'total' => 1_000]]);

    $report = reconService($woo)->compare('1403-07');

    expect($report->revenueDiff)->toBe(-5)->and($report->revenueDiffPct)->toBe('0.5000');
});

it('is green for an empty month on both sides, red when only the local side has revenue', function () {
    $empty = reconService(new WooOrderTotalsSimulator([]))->compare('1403-07');

    expect([$empty->wooOrderCount, $empty->localOrderCount, $empty->wooRevenue])->toBe([0, 0, 0])
        ->and($empty->status)->toBe(ReconciliationStatus::Green);

    reconLocal('2024-10-01 08:00:00', 250_000);
    $onlyLocal = reconService(new WooOrderTotalsSimulator([]))->compare('1403-07');

    expect($onlyLocal->revenueDiffPct)->toBe('999.9999')->and($onlyLocal->status)->toBe(ReconciliationStatus::Red);
});

it('leaves soft-deleted local orders out of both the count and the revenue', function () {
    reconSeedLocal(RECON_IN);
    reconLocal('2024-10-02 09:00:00', 400_000, 'completed', deleted: true);

    $report = reconService(new WooOrderTotalsSimulator(RECON_IN))->compare('1403-07');

    expect([$report->localOrderCount, $report->localRevenue])->toBe([4, 350_000])
        ->and($report->status)->toBe(ReconciliationStatus::Green);
});

it('reads local orders by their creation time in the window, not by any other date', function () {
    $order = reconLocal('2024-10-01 08:00:00', 100_000);
    $order->forceFill(['paid_at' => '2025-01-01 00:00:00', 'completed_at' => '2025-01-01 00:00:00', 'woo_modified_at' => '2025-01-01 00:00:00'])->save();

    $report = reconService(new WooOrderTotalsSimulator([['created' => '2024-10-01 08:00:00', 'status' => 'processing', 'total' => 100_000]]))->compare('1403-07');

    expect($report->localOrderCount)->toBe(1)->and($report->status)->toBe(ReconciliationStatus::Green);
});

// ================================================================== what is asked of Woo

it('asks Woo for the month window through the orders endpoint only: after = start - 1s, before = end, in GMT, and only the fields it needs', function () {
    $woo = reconAgreeing();

    reconService($woo)->compare('1403-07');

    expect(array_unique(array_column($woo->requests, 'endpoint')))->toBe(['orders'])
        ->and($woo->requests[0]['query'])->toBe([
            'after' => '2024-09-21T20:29:59',
            'before' => '2024-10-21T20:30:00',
            'dates_are_gmt' => 'true',
            'orderby' => 'date',
            'order' => 'asc',
            '_fields' => 'id,status,total,currency',
        ]);
});

it('reads every page with the same query and takes the count from the FIRST page\'s X-WP-Total', function () {
    reconSeedLocal(RECON_IN);
    $woo = new WooOrderTotalsSimulator(RECON_IN, perPage: 3); // 4 orders -> 2 pages

    $report = reconService($woo)->compare('1403-07');

    expect(array_column($woo->requests, 'page'))->toBe([1, 2])
        ->and($woo->requests[1]['query'])->toBe($woo->requests[0]['query'])
        ->and([$report->wooOrderCount, $report->wooRevenue])->toBe([4, 350_000]);
});

it('adds up revenue across all pages, exactly, in integer Toman', function () {
    $specs = array_map(fn (int $i): array => ['created' => '2024-10-01 08:00:00', 'status' => 'completed', 'total' => 1_234_567_890_123], range(1, 5));
    reconSeedLocal($specs);

    $report = reconService(new WooOrderTotalsSimulator($specs, perPage: 2))->compare('1403-07');

    expect($report->wooRevenue)->toBe(6_172_839_450_615)->and($report->localRevenue)->toBe(6_172_839_450_615)
        ->and($report->status)->toBe(ReconciliationStatus::Green);
});

it('never receives a customer field it did not ask for', function () {
    $woo = reconAgreeing();

    reconService($woo)->compare('1403-07');

    $page = $woo->page('orders', 1, null, $woo->requests[0]['query']);
    expect(array_keys($page->items[0]))->toBe(['id', 'status', 'currency', 'total']);
});

// ================================================================== failures of the reading

it('fails when the X-WP-Total of the first page does not match the orders actually read', function () {
    reconSeedLocal(RECON_IN);

    expect(fn () => reconService(new WooOrderTotalsSimulator(RECON_IN, reportedTotal: 3))->compare('1403-07'))
        ->toThrow(ReconciliationException::class, 'reported 3');
});

it('fails when Woo sends no X-WP-Total at all — for an empty month too, where a missing header must not pass as zero', function (array $orders) {
    expect(fn () => reconService(new WooOrderTotalsSimulator($orders, omitTotal: true))->compare('1403-07'))
        ->toThrow(ReconciliationException::class, 'X-WP-Total');
})->with(['a month with orders' => [RECON_IN], 'an empty month' => [[]]]);

it('refuses an order in another currency, revenue in mixed units is meaningless', function () {
    $woo = new WooOrderTotalsSimulator([...RECON_IN, ['created' => '2024-10-02 10:00:00', 'status' => 'completed', 'total' => 5, 'currency' => 'USD']]);

    expect(fn () => reconService($woo)->compare('1403-07'))->toThrow(WooCurrencyMismatchException::class);
});

it('refuses a total that is not a whole-Toman amount, naming the field and never the value', function () {
    $woo = new WooOrderTotalsSimulator([['created' => '2024-10-02 10:00:00', 'status' => 'completed', 'total' => 0, 'totalRaw' => '1000.50']]);

    try {
        reconService($woo)->compare('1403-07');
        $this->fail('expected a mapping failure');
    } catch (WooMappingException $e) {
        expect($e->field)->toBe('total')->and($e->getMessage())->not->toContain('1000.50');
    }
});

it('refuses a month that is not complete or not reconcilable, before asking Woo anything', function (string $month) {
    $woo = new WooOrderTotalsSimulator([]);

    expect(fn () => reconService($woo)->compare($month))->toThrow(InvalidArgumentException::class);

    expect($woo->requests)->toBe([])->and(ReconciliationReportModel::count())->toBe(0);
})->with(['the current month' => ['1403-10'], 'a future month' => ['1404-05'], 'before the epoch' => ['1403-06'], 'not a month' => ['1403-7']]);

// ================================================================== compare() is pure; reconcile() persists

it('does not write anything in compare()', function () {
    $service = reconService(reconAgreeing());
    DB::enableQueryLog();

    $service->compare('1403-07');

    $writes = array_filter(DB::getQueryLog(), fn (array $q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']) === 1);
    expect($writes)->toBe([])->and(ReconciliationReportModel::count())->toBe(0);
});

it('stores the report for the month in reconciliation_reports', function () {
    reconService(reconAgreeing())->reconcile('1403-07');

    $row = ReconciliationReportModel::sole();
    expect($row->jalali_month)->toBe('1403-07')
        ->and($row->period_start->toDateString())->toBe('2024-09-22')
        ->and($row->period_end->toDateString())->toBe('2024-10-21')
        ->and([$row->woo_orders, $row->crm_orders, $row->orders_diff])->toBe([4, 4, 0])
        ->and([$row->woo_revenue, $row->crm_revenue, $row->revenue_diff])->toBe([350_000, 350_000, 0])
        ->and($row->diff_percent)->toBe('0.0000')
        ->and($row->is_acceptable)->toBeTrue()
        ->and($row->status)->toBe(ReconciliationStatus::Green)
        ->and($row->error_message)->toBeNull()
        ->and($row->reconciled_at->utc()->format('Y-m-d\TH:i:s'))->toBe('2025-01-10T12:00:00')
        ->and($row->details)->toEqual([
            'window' => ['start' => '2024-09-21T20:30:00', 'end' => '2024-10-21T20:30:00'],
            'realized_statuses' => ['processing', 'completed'],
        ]);
});

it('returns the same report it stores', function () {
    $report = reconService(reconAgreeing())->reconcile('1403-07');

    expect($report->status)->toBe(ReconciliationStatus::Green)->and(ReconciliationReportModel::sole()->status)->toBe($report->status);
});

it('stores a red month as red and not acceptable', function () {
    reconSeedLocal(array_slice(RECON_IN, 0, 3));

    reconService(new WooOrderTotalsSimulator(RECON_IN))->reconcile('1403-07');

    $row = ReconciliationReportModel::sole();
    expect($row->status)->toBe(ReconciliationStatus::Red)->and($row->is_acceptable)->toBeFalse()->and($row->orders_diff)->toBe(1);
});

it('keeps ONE row per month: reconciling again updates it (recomputed, never accumulated)', function () {
    $service = reconService(reconAgreeing());
    $service->reconcile('1403-07');
    $first = ReconciliationReportModel::sole();

    reconLocal('2024-10-06 10:00:00', 50_000, 'completed');
    $this->travel(1)->hours();
    $service->reconcile('1403-07');

    $row = ReconciliationReportModel::sole();
    expect($row->id)->toBe($first->id)
        ->and($row->created_at->equalTo($first->created_at))->toBeTrue()
        ->and($row->reconciled_at->utc()->format('Y-m-d\TH:i:s'))->toBe('2025-01-10T13:00:00')
        ->and([$row->woo_orders, $row->crm_orders, $row->orders_diff])->toBe([4, 5, -1])
        ->and($row->status)->toBe(ReconciliationStatus::Red);
});

it('keeps separate rows for separate months', function () {
    $service = reconService(reconAgreeing());

    $service->reconcile('1403-07');
    $service->reconcile('1403-08');

    expect(ReconciliationReportModel::orderBy('jalali_month')->pluck('jalali_month')->all())->toBe(['1403-07', '1403-08']);
});

// ================================================================== failure: a failed row, the reason kept

it('records a failed month with the reason, clears its measurements, and lets the next success replace it', function () {
    $service = reconService(reconAgreeing());
    $service->reconcile('1403-07');

    $down = new WooRequestException('Woo GET orders failed with HTTP 503 (retries exhausted after 5 attempts).', 'orders', 503, 5, true, null);
    $failing = reconService(new WooOrderTotalsSimulator([], failure: $down));

    expect(fn () => $failing->reconcile('1403-07'))->toThrow(WooRequestException::class);

    $row = ReconciliationReportModel::sole();
    expect($row->status)->toBe(ReconciliationStatus::Failed)
        ->and($row->is_acceptable)->toBeFalse()
        ->and($row->error_message)->toContain('WooRequestException')->toContain('HTTP 503')
        ->and([$row->woo_orders, $row->crm_orders, $row->woo_revenue, $row->crm_revenue, $row->orders_diff, $row->revenue_diff, $row->diff_percent])->toBe([null, null, null, null, null, null, null])
        ->and($row->reconciled_at)->toBeNull()
        ->and($row->period_start->toDateString())->toBe('2024-09-22');

    reconService(reconAgreeing2())->reconcile('1403-07');

    $healed = ReconciliationReportModel::sole();
    expect($healed->status)->toBe(ReconciliationStatus::Green)->and($healed->error_message)->toBeNull()->and($healed->reconciled_at)->not->toBeNull();
});

/** The Woo side of the standard month again, for a second attempt (the local side is already seeded). */
function reconAgreeing2(): WooOrderTotalsSimulator
{
    return new WooOrderTotalsSimulator([...RECON_IN, ...RECON_OUT]);
}

it('records a failed month for a currency or amount problem too, once, on the same row', function () {
    $woo = new WooOrderTotalsSimulator([['created' => '2024-10-02 10:00:00', 'status' => 'completed', 'total' => 5, 'currency' => 'USD']]);

    expect(fn () => reconService($woo)->reconcile('1403-07'))->toThrow(WooCurrencyMismatchException::class);

    expect(ReconciliationReportModel::sole()->status)->toBe(ReconciliationStatus::Failed);
});

it('never lets a phone number reach error_message, whatever an exception says', function () {
    $woo = new WooOrderTotalsSimulator([], failure: new RuntimeException('bad billing 09121234567 / +98 912 123 4567 / ۰۹۱۲۳۴۵۶۷۸۹'));

    expect(fn () => reconService($woo)->reconcile('1403-07'))->toThrow(RuntimeException::class);

    $message = (string) ReconciliationReportModel::sole()->error_message;
    expect($message)->toContain('RuntimeException')->not->toContain('09121234567')->not->toContain('912 123 4567')->not->toContain('۰۹۱۲۳۴۵۶۷۸۹');
});

it('writes no row at all for a month it refuses (an invalid month has nothing to attach a failure to)', function () {
    $service = reconService(new WooOrderTotalsSimulator([]));

    expect(fn () => $service->reconcile('1403-10'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->reconcile('nonsense'))->toThrow(InvalidArgumentException::class);

    expect(ReconciliationReportModel::count())->toBe(0);
});

// ================================================================== all the months

it('dispatches one job per complete month, from the first month to the last complete one, on the sync queue', function () {
    Queue::fake();

    $months = reconService(new WooOrderTotalsSimulator([]))->dispatchAllMonths();

    expect($months)->toBe(['1403-07', '1403-08', '1403-09']);
    Queue::assertPushed(ReconcileMonthJob::class, 3);
    Queue::assertPushedOn('sync', ReconcileMonthJob::class, fn (ReconcileMonthJob $job) => $job->jalaliMonth === '1403-09');
    Queue::assertNotPushed(ReconcileMonthJob::class, fn (ReconcileMonthJob $job) => $job->jalaliMonth === '1403-10');
});

it('dispatches nothing while no month is complete', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2024-10-01 12:00:00', 'UTC'));

    expect(reconService(new WooOrderTotalsSimulator([]))->dispatchAllMonths())->toBe([]);
    Queue::assertNothingPushed();
});

// ================================================================== GATE 1 is checkable — and never passes by default

function reconRow(string $month, ?ReconciliationStatus $status, array $overrides = []): ReconciliationReportModel
{
    [$start, $end] = (new ReconciliationMonths)->dates($month);
    $measured = $status !== ReconciliationStatus::Failed;

    return ReconciliationReportModel::create([
        'jalali_month' => $month, 'period_start' => $start, 'period_end' => $end, 'status' => $status,
        'woo_orders' => $measured ? 100 : null, 'crm_orders' => $measured ? 100 : null,
        'woo_revenue' => $measured ? 1_000_000 : null, 'crm_revenue' => $measured ? 1_000_000 : null,
        'orders_diff' => $measured ? 0 : null, 'revenue_diff' => $measured ? 0 : null, 'diff_percent' => $measured ? '0.0000' : null,
        'is_acceptable' => $status === ReconciliationStatus::Green,
        'error_message' => $status === ReconciliationStatus::Failed ? 'RuntimeException: boom' : null,
        'reconciled_at' => $measured ? CarbonImmutable::now('UTC') : null,
        ...$overrides,
    ]);
}

it('keeps Gate 1 OPEN when nothing has been reconciled: no evidence is not a pass', function () {
    $gate = reconService(new WooOrderTotalsSimulator([]))->gateOne();

    expect($gate->passed)->toBeFalse()
        ->and(array_map(fn ($m) => $m->month, $gate->months))->toBe(['1403-07', '1403-08', '1403-09'])
        ->and(array_map(fn ($m) => $m->status, $gate->months))->toBe([null, null, null])
        ->and($gate->missingMonths())->toBe(['1403-07', '1403-08', '1403-09'])
        ->and($gate->failingMonths())->toBe([]);
});

it('passes Gate 1 only when EVERY month from the first to the last complete one is green', function () {
    foreach (['1403-07', '1403-08', '1403-09'] as $month) {
        reconRow($month, ReconciliationStatus::Green);
    }

    $gate = reconService(new WooOrderTotalsSimulator([]))->gateOne();

    expect($gate->passed)->toBeTrue()->and($gate->missingMonths())->toBe([])->and($gate->failingMonths())->toBe([]);
});

it('keeps Gate 1 open for a red month and says which and by how much', function () {
    reconRow('1403-07', ReconciliationStatus::Green);
    reconRow('1403-08', ReconciliationStatus::Red, ['orders_diff' => 3, 'revenue_diff' => 40_000, 'diff_percent' => '4.0000', 'is_acceptable' => false]);
    reconRow('1403-09', ReconciliationStatus::Green);

    $gate = reconService(new WooOrderTotalsSimulator([]))->gateOne();

    expect($gate->passed)->toBeFalse()->and($gate->failingMonths())->toBe(['1403-08']);
    $red = $gate->months[1];
    expect([$red->month, $red->status, $red->countDiff, $red->revenueDiffPct])->toBe(['1403-08', ReconciliationStatus::Red, 3, '4.0000']);
});

it('keeps Gate 1 open for a failed month and for a missing one', function () {
    reconRow('1403-07', ReconciliationStatus::Green);
    reconRow('1403-08', ReconciliationStatus::Failed);

    $gate = reconService(new WooOrderTotalsSimulator([]))->gateOne();

    expect($gate->passed)->toBeFalse()->and($gate->failingMonths())->toBe(['1403-08'])->and($gate->missingMonths())->toBe(['1403-09']);
});

it('ignores reports outside the gate: the running month and months before the epoch neither pass nor fail it', function () {
    reconRow('1403-10', ReconciliationStatus::Red); // the current month: not judged yet
    reconRow('1403-06', ReconciliationStatus::Red); // before the epoch
    foreach (['1403-07', '1403-08', '1403-09'] as $month) {
        reconRow($month, ReconciliationStatus::Green);
    }

    expect(reconService(new WooOrderTotalsSimulator([]))->gateOne()->passed)->toBeTrue();
});

it('keeps Gate 1 open while there is no complete month to judge', function () {
    $this->travelTo(CarbonImmutable::parse('2024-10-01 12:00:00', 'UTC'));

    $gate = reconService(new WooOrderTotalsSimulator([]))->gateOne();

    expect($gate->passed)->toBeFalse()->and($gate->months)->toBe([]);
});

it('describes a report in one line of counts and a percentage, nothing else', function () {
    $report = reconService(reconAgreeing())->compare('1403-07');

    expect($report->summary())->toBe('1403-07: green (orders 4/4, revenue diff 0.0000%)');
});
