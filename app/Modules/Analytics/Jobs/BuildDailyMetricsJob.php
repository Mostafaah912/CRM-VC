<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Services\DailyMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queues DailyMetricsService::rebuild() (PRD §16/§22) — a thin wrapper, no business logic here
 * (CLAUDE.md §1/§5: "a Job resolves a Service and calls it"). `$days` defaults to 3, matching PRD
 * §22's literal nightly-chain call `BuildDailyMetricsJob(3)`.
 *
 * Queue = `metrics` (PRD §22 only names critical/sync/metrics/ai/default; same choice already made for
 * `RebuildAllSegmentsJob` and `BuildCustomerPurchaseAggregatesJob`): the nightly chain runs this right
 * after `BuildCustomerPurchaseAggregatesJob`, itself right after `RecomputeMetricsJob('full')` — this
 * job's own `customers_new`/`customers_repeat` split depends on `customer_metrics.first_order_at`
 * being fresh, so it belongs on the same worker pool, behind that dependency.
 *
 * `tries = 1`, `ShouldBeUnique` + `uniqueFor > timeout` (PRD §22's rule for every whole-data job): a
 * second rebuild racing the first would upsert the same days from two directions at once.
 */
final class BuildDailyMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /** `$asOf` (Gate-2-style determinism, see RecomputeMetricsJob): never passed in production. */
    public function __construct(public readonly int $days = 3, public readonly ?CarbonImmutable $asOf = null)
    {
        $this->onQueue('metrics');
    }

    public function uniqueId(): string
    {
        return 'build-daily-metrics';
    }

    public function handle(DailyMetricsService $dailyMetrics): void
    {
        $summary = $dailyMetrics->rebuild($this->days, $this->asOf);

        Log::info('BuildDailyMetricsJob finished', [
            'days_processed' => $summary->daysProcessed,
            'elapsed_ms' => $summary->elapsedMs,
        ]);
    }
}
