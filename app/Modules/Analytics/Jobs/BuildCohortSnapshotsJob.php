<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Services\CohortSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queues CohortSnapshotService::rebuild() (PRD §15/§22) — a thin wrapper, no business logic here
 * (CLAUDE.md §1/§5: "a Job resolves a Service and calls it"). PRD §22 lists this with no arguments,
 * unlike `BuildDailyMetricsJob(3)` — a full rebuild every run, matching P6-01's purchase aggregates.
 *
 * Queue = `metrics` (PRD §22 only names critical/sync/metrics/ai/default; same choice already made for
 * `RebuildAllSegmentsJob`/`BuildCustomerPurchaseAggregatesJob`/`BuildDailyMetricsJob`): the nightly chain
 * runs this right after `BuildDailyMetricsJob(3)`, itself after `RecomputeMetricsJob('full')` — this
 * job's `cohort_month` split depends on `customer_metrics.cohort_month` being fresh, so it belongs on
 * the same worker pool, behind that dependency.
 *
 * `tries = 1`, `ShouldBeUnique` + `uniqueFor > timeout` (PRD §22's rule for every whole-data job): a
 * second rebuild racing the first would TRUNCATE the table the first one is still inserting into.
 */
final class BuildCohortSnapshotsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /** `$asOf` (Gate-2-style determinism, see RecomputeMetricsJob): never passed in production. */
    public function __construct(public readonly ?CarbonImmutable $asOf = null)
    {
        $this->onQueue('metrics');
    }

    public function uniqueId(): string
    {
        return 'build-cohort-snapshots';
    }

    public function handle(CohortSnapshotService $cohorts): void
    {
        $summary = $cohorts->rebuild($this->asOf);

        Log::info('BuildCohortSnapshotsJob finished', [
            'rows_written' => $summary->rowsWritten,
            'elapsed_ms' => $summary->elapsedMs,
        ]);
    }
}
