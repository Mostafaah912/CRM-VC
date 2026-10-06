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
