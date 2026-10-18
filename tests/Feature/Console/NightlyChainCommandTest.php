<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\BuildCohortSnapshotsJob;
use App\Modules\Analytics\Jobs\BuildCustomerPurchaseAggregatesJob;
use App\Modules\Analytics\Jobs\BuildDailyMetricsJob;
use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Orders\Models\Order;
use App\Modules\Segments\Jobs\RebuildAllSegmentsJob;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Jobs\ReconcileRecentMonthsJob;
use App\Modules\Sync\Jobs\SyncEntityJob;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooPage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/*
| P6-09 — `hm:nightly-chain` (PRD §22's nightly Bus::chain): catalog+orders sync, a full metrics recompute,
| every P6-01..05 Analytics rebuild, segments, then a recent-months reconciliation, in that exact order.
| PRD §22 itself explains why a chain and not independent dispatches: "ترتیب حیاتی است. Bus::chain در
| اولین شکست متوقف می‌شود — بهتر است چیزی اجرا نشود تا با داده ناقص اجرا شود" — native Bus::chain
| stop-at-first-failure semantics are the point, not a detail to work around.
*/

it('chains every nightly step in PRD §22 order', function () {
    Bus::fake();

    Artisan::call('hm:nightly-chain');

    Bus::assertChained([
        CatalogSyncJob::class,
        SyncEntityJob::class,
        RecomputeMetricsJob::class,
        BuildCustomerPurchaseAggregatesJob::class,
        BuildDailyMetricsJob::class,
        BuildCohortSnapshotsJob::class,
        RebuildAllSegmentsJob::class,
        ReconcileRecentMonthsJob::class,
    ]);
});

it('recomputes metrics in full mode, not dirty (PRD §22\'s nightly chain step)', function () {
    Bus::fake();

    Artisan::call('hm:nightly-chain');

    Bus::assertChained([
        CatalogSyncJob::class,
        SyncEntityJob::class,
        fn (RecomputeMetricsJob $job) => $job->runType === 'full',
        BuildCustomerPurchaseAggregatesJob::class,
        BuildDailyMetricsJob::class,
        BuildCohortSnapshotsJob::class,
        RebuildAllSegmentsJob::class,
        ReconcileRecentMonthsJob::class,
    ]);
});

/*
| The real risk this chain exists to close (already logged as a risk in P6-02/03/04, and as a documented
| manual workaround in docs/architecture/sprint-5.md, until P6-09 shipped): BuildCohortSnapshotsJob reads
| customer_metrics.cohort_month directly, which only RecomputeMetricsJob('full') ever writes. This test
| runs the real ordering-sensitive part of the chain end to end (no Woo/network involved, so no fake
| client needed) against a customer who has NO customer_metrics row yet, and proves that, in this order,
| the dependency is actually satisfied — not just wired in the right sequence on paper (the test above).
| phpunit.xml sets QUEUE_CONNECTION=sync, so a real Bus::chain()->dispatch() runs every step synchronously,
| in process, through the same chain-progression Laravel uses in production.
*/
it('delivers a fresh cohort_month to BuildCohortSnapshotsJob because RecomputeMetricsJob(full) runs first', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(5)]);

    expect(DB::table('customer_metrics')->where('customer_id', $customer->id)->exists())->toBeFalse();

    Bus::chain([
        new RecomputeMetricsJob('full'),
        new BuildCustomerPurchaseAggregatesJob,
        new BuildDailyMetricsJob(3),
        new BuildCohortSnapshotsJob,
    ])->dispatch();

    $cohortMonth = DB::table('customer_metrics')->where('customer_id', $customer->id)->value('cohort_month');
    expect($cohortMonth)->not->toBeNull();
    expect(DB::table('cohort_snapshots')->where('cohort_month', $cohortMonth)->where('period_number', 0)->value('cohort_size'))->toBe(1);
});

/*
| P7-08 follow-up. PRD §22 wants the chain to stop at the first failure "so nothing runs on incomplete data" — and that stays true for every
| DATA step. The catalog is the one exception: it is a mirror of Woo's product list that none of the metrics steps read (RecomputeMetrics,
| daily metrics, cohorts, segments and reconciliation work from orders), so last night's copy serves; a catalog timeout used to stop the whole
| chain every night. Only the catalog step is fail-soft; a failing data step still stops everything after it.
*/

it('wires the catalog step fail-soft and every other step as before', function () {
    Bus::fake();

    Artisan::call('hm:nightly-chain');

    Bus::assertChained([
        fn (CatalogSyncJob $job) => $job->failSoft === true,
        SyncEntityJob::class,
        RecomputeMetricsJob::class,
        BuildCustomerPurchaseAggregatesJob::class,
        BuildDailyMetricsJob::class,
        BuildCohortSnapshotsJob::class,
        RebuildAllSegmentsJob::class,
        ReconcileRecentMonthsJob::class,
    ]);
});

it('a catalog failure does NOT stop the chain: the next step still runs (and a fail-hard step would have stopped it)', function () {
    app()->instance(WooClient::class, new class implements WooClient
    {
        public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
        {
            throw new WooRequestException('timeout', $endpoint, null, 5, true);
        }

        public function pages(string $endpoint, ?SyncWindow $window = null, array $query = []): Generator
        {
            throw new WooRequestException('timeout', $endpoint, null, 5, true);
            yield;
        }
    });
    $GLOBALS['chain_probe'] = 0;
    $probe = function () {
        $GLOBALS['chain_probe']++;
    };

    Bus::chain([new CatalogSyncJob(failSoft: true), $probe])->dispatch();
    expect($GLOBALS['chain_probe'])->toBe(1);

    $GLOBALS['chain_probe'] = 0;

    try {
        Bus::chain([new CatalogSyncJob(failSoft: false), $probe])->dispatch();
    } catch (WooRequestException) {
    }

    expect($GLOBALS['chain_probe'])->toBe(0);   // the control: without fail-soft the chain stops, which is what happened every night
});

it('still stops the chain when a DATA step fails', function () {
    $GLOBALS['chain_probe'] = 0;
    $probe = function () {
        $GLOBALS['chain_probe']++;
    };

    try {
        Bus::chain([function () {
            throw new RuntimeException('metrics failed');
        }, $probe])->dispatch();
    } catch (RuntimeException) {
    }

    expect($GLOBALS['chain_probe'])->toBe(0);
});
