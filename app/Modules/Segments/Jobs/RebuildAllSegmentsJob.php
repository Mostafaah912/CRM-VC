<?php

declare(strict_types=1);

namespace App\Modules\Segments\Jobs;

use App\Modules\Segments\Services\SegmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * P5-08 (PRD §22): queues SegmentService::rebuildAll() — a thin wrapper, no business logic here
 * (CLAUDE.md §1/§5: "a Job resolves a Service and calls it"). The per-segment loop, the "don't let
 * one bad rule stop the rest" isolation, and the lookup-by-id all live in the Service (a Job may
 * never import a module Model).
 *
 * Queue = `metrics` (PRD §22 only names critical/sync/metrics/ai/default; Segments has no queue of
 * its own): this job reads exclusively from `customer_metrics`-derived fields and, per PRD §22's own
 * nightly chain, always runs immediately after `RecomputeMetricsJob('full')` — putting it on the same
 * worker pool keeps it behind that dependency instead of racing ahead of it on `default`.
 *
 * `tries = 1`: a run that failed partway already recorded (and logged) exactly which segments failed;
 * a blind retry would just repeat whatever made them fail, while the ones that already succeeded were
 * already re-evaluated correctly. `ShouldBeUnique` + `uniqueFor > timeout` (PRD §22's own rule for
 * every whole-data job): at most one rebuild in flight — a second one racing the first would read the
 * first's half-written `segment_members` rows for whichever segment is mid-evaluate.
 */
final class RebuildAllSegmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function uniqueId(): string
    {
        return 'rebuild-all-segments';
    }

    public function handle(SegmentService $segments): void
    {
        $summary = $segments->rebuildAll();

        Log::info('RebuildAllSegmentsJob finished', [
            'succeeded' => count($summary->succeededSegmentIds),
            'failed' => count($summary->failedSegmentIds),
            'failed_segment_ids' => array_keys($summary->failedSegmentIds),
            'elapsed_ms' => $summary->elapsedMs,
        ]);
    }
}
