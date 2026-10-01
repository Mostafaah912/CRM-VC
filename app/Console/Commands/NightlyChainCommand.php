<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Analytics\Jobs\BuildCohortSnapshotsJob;
use App\Modules\Analytics\Jobs\BuildCustomerPurchaseAggregatesJob;
use App\Modules\Analytics\Jobs\BuildDailyMetricsJob;
use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Segments\Jobs\RebuildAllSegmentsJob;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Jobs\ReconcileRecentMonthsJob;
use App\Modules\Sync\Jobs\SyncEntityJob;
use App\Modules\Sync\Support\SafeErrorText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `hm:nightly-chain` (P6-09, PRD §22): queues the whole nightly chain as ONE Bus::chain, in the exact
 * order PRD §22 gives — no logic here, only the wiring (CLAUDE.md §1/§5).
 *
 * Steps, and why this order (each dependency already documented, individually, on the job it protects):
 *  1. CatalogSyncJob            - categories/products/variations, a full mirror every run.
 *  2. SyncEntityJob(Orders)     - incremental: the 15-minute poll (still scheduled separately below)
 *                                 already keeps orders close to live; this step only catches up on
 *                                 whatever landed in the last few minutes before metrics run.
 *  3. RecomputeMetricsJob(full) - customer_metrics must be fresh before ANY step below reads it.
 *  4. BuildCustomerPurchaseAggregatesJob, 5. BuildDailyMetricsJob(3), 6. BuildCohortSnapshotsJob
 *                                 - each depends on step 3's customer_metrics (cohort_month,
 *                                 first_order_at) being fresh; their own docblocks say so.
 *  7. RebuildAllSegmentsJob     - segment rules read customer_metrics/RFM/churn fields, also fresh
 *                                 only after step 3.
 *  8. ReconcileRecentMonthsJob(2) - PRD §22's "ReconcileJob(2)": the last 2 complete months only.
 *
 * PRD §22 is explicit about failure handling, not silent: "ترتیب حیاتی است. Bus::chain در اولین شکست
 * متوقف می‌شود — بهتر است چیزی اجرا نشود تا با داده ناقص اجرا شود." Native Bus::chain semantics
 * (stop at the first failed step, skip the rest) are followed as-is — not overridden into
 * log-and-continue, which PRD is not silent about here.
 *
 * "Customers" sync (PRD §22's chain literally names it as its own step) has no separate job: customer
 * identity is resolved as a side effect of order ingestion (CustomerIdentityService, called from within
 * OrderSyncService), not a standalone Woo customers-endpoint pull — SyncEntity has no Customers case
 * ("customers are not run through here yet", its own docblock). Step 2 already covers it.
 */
final class NightlyChainCommand extends Command
{
    protected $signature = 'hm:nightly-chain';

    protected $description = 'Queue the nightly chain (PRD §22): catalog+orders sync, full metrics recompute, every P6 analytics rebuild, segments, then recent-month reconciliation — stops at the first failed step';

    public function handle(): int
    {
        Bus::chain([
            new CatalogSyncJob,
            new SyncEntityJob(SyncEntity::Orders, SyncMode::Incremental),
            new RecomputeMetricsJob('full'),
            new BuildCustomerPurchaseAggregatesJob,
            new BuildDailyMetricsJob(3),
            new BuildCohortSnapshotsJob,
            new RebuildAllSegmentsJob,
            new ReconcileRecentMonthsJob(2),
        ])->catch(function (Throwable $e): void {
            Log::error('Nightly chain stopped: a step failed', ['error' => SafeErrorText::from($e, 300)]);
        })->dispatch();

        $this->line('Dispatched the nightly chain');

        return self::SUCCESS;
    }
}
