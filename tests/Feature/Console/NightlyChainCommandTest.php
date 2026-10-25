<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\BuildCohortSnapshotsJob;
use App\Modules\Analytics\Jobs\BuildCustomerPurchaseAggregatesJob;
use App\Modules\Analytics\Jobs\BuildDailyMetricsJob;
use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Orders\Models\Order;
use App\Modules\Segments\Jobs\RebuildAllSegmentsJob;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Jobs\ReconcileRecentMonthsJob;
use App\Modules\Sync\Jobs\SyncEntityJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/*
| P6-09 — `hm:nightly-chain` (PRD §22's nightly Bus::chain): the orders sync, a full metrics recompute (the catalog is NOT a step any more — P8-03g),
| every P6-01..05 Analytics rebuild, segments, then a recent-months reconciliation, in that exact order.
| PRD §22 itself explains why a chain and not independent dispatches: "ترتیب حیاتی است. Bus::chain در
| اولین شکست متوقف می‌شود — بهتر است چیزی اجرا نشود تا با داده ناقص اجرا شود" — native Bus::chain
| stop-at-first-failure semantics are the point, not a detail to work around.
*/

it('chains every nightly step in PRD §22 order', function () {
    Bus::fake();

    Artisan::call('hm:nightly-chain');

    Bus::assertChained([
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
| P8-03g. PRD §22 wants the chain to stop at the first failure "so nothing runs on incomplete data" — and that stays true for every DATA
| step. The catalog used to be step 1, made fail-soft in the P7-08 follow-up; but a worker TIMEOUT kill cannot be caught by any fail-soft
| (the process is killed), so on 2026-10-25 a 30-minute catalog timeout stopped the whole chain: no metrics, daily, cohort, segments or
| reconcile ran. The catalog now has its own schedule (01:00 Tehran) and is simply not in the chain.
*/

it('does not contain the catalog sync at all: a slow or dead Woo cannot stop the metrics steps', function () {
    Bus::fake();

    Artisan::call('hm:nightly-chain');

    Bus::assertChained([
        SyncEntityJob::class,
        RecomputeMetricsJob::class,
        BuildCustomerPurchaseAggregatesJob::class,
        BuildDailyMetricsJob::class,
        BuildCohortSnapshotsJob::class,
        RebuildAllSegmentsJob::class,
        ReconcileRecentMonthsJob::class,
    ]);
    Bus::assertNotDispatched(CatalogSyncJob::class);
    expect(file_get_contents(base_path('app/Console/Commands/NightlyChainCommand.php')))->not->toContain('new CatalogSyncJob');
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
