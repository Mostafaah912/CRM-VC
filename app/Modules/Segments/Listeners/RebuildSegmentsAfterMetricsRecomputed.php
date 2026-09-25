<?php

declare(strict_types=1);

namespace App\Modules\Segments\Listeners;

use App\Modules\Metrics\Events\MetricsRecomputed;
use App\Modules\Segments\Jobs\RebuildAllSegmentsJob;

/**
 * P5-08. PRD §22's own Scheduler spec settles the "dirty (hourly) or full (nightly), or both"
 * question explicitly, without needing a new default: the `hourly:` line lists only
 * `SyncRefundsJob, RecomputeMetricsJob('dirty')` — `RebuildAllSegmentsJob` appears solely in the
 * `daily 03:00` chain, right after `RecomputeMetricsJob('full')`. So this only reacts to a full run;
 * a dirty run (hourly) never dispatches a rebuild. See docs/architecture/sprint-5.md (P5-08) for the
 * dev-measured cost of what an hourly dispatch would have cost, and why skipping it matches the PRD.
 *
 * `MetricsRecomputed::isFullRun()` exists so this listener never imports Metrics\Enums\MetricRunMode
 * itself (tests/Arch/ArchitectureTest.php: cross-module only through a Service or an Event).
 */
final class RebuildSegmentsAfterMetricsRecomputed
{
    public function handle(MetricsRecomputed $event): void
    {
        if (! $event->isFullRun()) {
            return;
        }

        RebuildAllSegmentsJob::dispatch();
    }
}
