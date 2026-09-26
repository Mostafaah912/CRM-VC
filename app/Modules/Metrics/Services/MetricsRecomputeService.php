<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Events\MetricsRecomputed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PRD §11's full pipeline, orchestrated in the one fixed order every step actually depends on: base
 * aggregates (P4-01) must exist before purchase cycle (P4-02, reads recency_days/frequency), which
 * must exist before RFM (P4-03, reads recency_days/frequency/monetary) and CLV (P4-04, reads
 * aov/purchase_cycle_days); churn (P4-05) needs both the store thresholds AND m_score (from RFM) AND
 * purchase_cycle_days (from step 2); lifecycle (P4-06) needs the same thresholds and
 * total_orders/recency_days. Nothing here is reorderable.
 *
 * Lives as a Service (not inline in RecomputeMetricsJob::handle()) because CLAUDE.md §1/§5 bans
 * business logic — including DB:: — in Jobs: "A Job resolves a Service and calls it." The Job is a
 * thin `queue`/`tries`/`ShouldBeUnique` wrapper; this class holds the actual orchestration.
 *
 * The computation steps (percentiles through lifecycle) run inside one DB::transaction(): none of the
 * individual calculators open their own transaction, so without this wrapper a failure halfway through
 * (say, RFM succeeds but CLV throws) would leave RFM's scores permanently written against a run
 * recorded as `failed` — exactly the half-written state a single-attempt job (tries=1, see the Job's
 * own docblock) exists to prevent. Wrapping them lets a failure roll back every partial write from that
 * attempt in one step, so `metric_runs.status='failed'` always means "nothing from this run stuck,"
 * never "some of it did" — and it also means the run's own `fail()` write is never attempted inside an
 * already-poisoned transaction left behind by whichever step actually threw.
 *
 * PRD §11 step 11 (`metrics_dirty = false`) is the last write inside that same transaction, via
 * {@see BaseAggregateService::resetDirtyFlag()} — so a failure anywhere above it rolls the reset back
 * too, and a customer is never marked clean without every later step having actually run against it.
 * Known race, accepted rather than solved here (see docs/architecture/sprint-6.md): an order that
 * lands for a customer *during* this transaction, after step 3 read their aggregates but before this
 * reset commits, gets its dirty flag cleared here anyway — that order's effect is picked up by the
 * next nightly full run, not the next dirty run.
 */
final class MetricsRecomputeService
{
    public function __construct(
        private readonly MetricRunService $runs,
        private readonly ChurnThresholdService $churnThresholds,
        private readonly BaseAggregateService $baseAggregates,
        private readonly PurchaseCycleService $purchaseCycle,
        private readonly RfmCalculator $rfm,
        private readonly ClvCalculator $clv,
        private readonly ChurnCalculator $churn,
        private readonly LifecycleStageResolver $lifecycle,
    ) {}

    /**
     * `$asOf` (P4-08, Gate 2): overrides the instant base aggregates measures recency against — only
     * ever passed explicitly by the Gate 2 test, which anchors the whole pipeline to
     * DemoDataSeeder::AS_OF so it can be compared against a fixture frozen at that same instant.
     * Production callers (the Job, the console command) never pass it, so behavior there is unchanged.
     */
    public function run(string $runType, ?CarbonImmutable $asOf = null): void
    {
        $mode = MetricRunMode::from($runType);
        $run = $this->runs->start($mode);

        try {
            $count = DB::transaction(function () use ($mode, $run, $asOf): int {
                $thresholds = $this->churnThresholds->percentiles();
                $this->churnThresholds->saveToRun($run, $thresholds);

                $count = $mode === MetricRunMode::Full
                    ? $this->baseAggregates->computeAll($run->id, $asOf)
                    : $this->baseAggregates->computeDirty($run->id, $asOf);

                $this->purchaseCycle->compute($thresholds);
                $this->rfm->compute();
                $this->clv->compute();
                $this->churn->compute($thresholds);
                $this->lifecycle->resolve($thresholds);
                $this->baseAggregates->resetDirtyFlag($run->id);

                return $count;
            });

            $this->runs->finish($run, $count);

            event(new MetricsRecomputed($run));
        } catch (Throwable $e) {
            $this->runs->fail($run, $e->getMessage());

            throw $e;
        }
    }
}
