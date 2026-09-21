<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Jobs;

use App\Modules\Metrics\Services\MetricsRecomputeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queues MetricsRecomputeService::run() (PRD §11's full pipeline) — a thin wrapper, no business logic
 * here (CLAUDE.md §1/§5: "a Job resolves a Service and calls it").
 *
 * `tries = 1`: a metrics run that partially failed must never be silently retried — a retry would recompute
 * over whatever half-written state the failure left, and wrong customer-facing numbers (segments, CLV, churn
 * risk) are worse than a run that visibly failed and waits for the next nightly/dirty trigger.
 *
 * `ShouldBeUnique` + `uniqueFor = 1800`: at most one recompute (of either type) may run at a time — a second
 * one racing the first would read the first's half-written customer_metrics rows. `uniqueId()` is a fixed
 * string (not per run type) on purpose: a burst of orders dispatching several 'dirty' jobs 5 minutes apart
 * (see MarkCustomerMetricsDirty) collapses into one, and a 'dirty' dispatch arriving while a nightly 'full'
 * run is in flight is correctly dropped — the full run already covers what the dirty one would have done.
 */
final class RecomputeMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public readonly string $runType = 'full')
    {
        $this->onQueue('metrics');
    }

    public function uniqueId(): string
    {
        return 'recompute-metrics';
    }

    public function handle(MetricsRecomputeService $service): void
    {
        $service->run($this->runType);
    }
}
