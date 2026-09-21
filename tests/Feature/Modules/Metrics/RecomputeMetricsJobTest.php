<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Events\MetricsRecomputed;
use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Orders\Models\Order;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| P4-07: RecomputeMetricsJob orchestrates PRD §11's whole pipeline in the one order every step actually
| depends on. `metric_runs.mode` is the real column name (not "run_type" — a PRD §09 naming correction
| already made in P4-01/02). tries=1 and no automatic retry: a job that throws mid-pipeline must leave a
| `failed` metric_runs row, not a silent partial recompute retried over half-written data.
|
| Every Metrics service (BaseAggregateService, PurchaseCycleService, RfmCalculator, ClvCalculator,
| ChurnCalculator, LifecycleStageResolver) is `final class` (a deliberate P4-01..06 choice, not
| accidental) — Mockery cannot generate a subclass mock for a final class, and handle()'s parameters
| are type-hinted against the concrete classes, so a non-instance mock fails PHP's own type check
| before Mockery even matters. Rather than stripping `final` from six already-shipped classes purely
| for test convenience, the pipeline-order and failure tests below use real data and a real (though
| synthetically triggered) database error — which also verifies actual data correctness between steps,
| not just that methods were called in some sequence.
*/

it('creates a completed metric run for a full recompute', function () {
    RecomputeMetricsJob::dispatchSync('full');

    $run = DB::table('metric_runs')->latest('id')->first();
    expect($run->status)->toBe('completed')
        ->and($run->mode)->toBe('full');
});

it('processes a customer whose metrics_dirty is false on a full run: full means computeAll, never computeDirty', function () {
    $customer = Customer::factory()->create(['metrics_dirty' => false]);
    Order::factory()->for($customer)->create(['is_realized' => true]);

    RecomputeMetricsJob::dispatchSync('full');

    expect(DB::table('customer_metrics')->where('customer_id', $customer->id)->value('total_orders'))->toBe(1);
});

it('saves the store thresholds onto the metric run', function () {
    RecomputeMetricsJob::dispatchSync('full');

    $run = DB::table('metric_runs')->latest('id')->first();
    expect($run->thresholds)->not->toBeNull();
    expect(json_decode((string) $run->thresholds, true))->toHaveKeys(['p50', 'p75', 'p90']);
});

it('records the dirty run type', function () {
    RecomputeMetricsJob::dispatchSync('dirty');

    $run = DB::table('metric_runs')->latest('id')->first();
    expect($run->mode)->toBe('dirty');
});

it('fires MetricsRecomputed after a successful run', function () {
    Event::fake([MetricsRecomputed::class]);

    RecomputeMetricsJob::dispatchSync('full');

    Event::assertDispatched(MetricsRecomputed::class);
});

it('marks the metric run failed and rethrows when a step genuinely fails mid-pipeline', function () {
    // A real Postgres error inside PurchaseCycleService's step, after MetricRunService::start() already
    // created the run — proves the try/catch/fail/rethrow wraps the whole pipeline, not just one step.
    DB::statement('ALTER TABLE customer_metrics DROP COLUMN purchase_cycle_days');

    expect(fn () => RecomputeMetricsJob::dispatchSync('full'))->toThrow(QueryException::class);

    $run = DB::table('metric_runs')->latest('id')->first();
    expect($run->status)->toBe('failed')
        ->and($run->error)->not->toBeNull();
});

it('populates customer_metrics and resolves a real lifecycle stage after a full run against demo data', function () {
    app(DemoDataSeeder::class)->run();

    RecomputeMetricsJob::dispatchSync('full');

    expect(DB::table('customer_metrics')->count())->toBeGreaterThan(0);
    expect(
        DB::table('customers')
            ->join('customer_metrics', 'customer_metrics.customer_id', '=', 'customers.id')
            ->where('customer_metrics.total_orders', '>=', 1)
            ->where('customers.lifecycle_stage', 'prospect')
            ->count()
    )->toBe(0);
});

it('respects the pipeline order via the real data dependency chain: base -> cycle -> rfm -> churn -> lifecycle', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(10)]);

    RecomputeMetricsJob::dispatchSync('full');

    $metrics = DB::table('customer_metrics')->where('customer_id', $customer->id)->first();
    expect($metrics)->not->toBeNull()
        // Only non-null if BaseAggregateService already ran (customer_metrics row must exist).
        ->and($metrics->recency_days)->not->toBeNull()
        // Only non-null if PurchaseCycleService ran AFTER the row existed.
        ->and($metrics->purchase_cycle_days)->not->toBeNull();
    expect([$metrics->r_score, $metrics->f_score, $metrics->m_score])->each->not->toBeNull();
    // churn_risk_score divides by purchase_cycle_days: NULL here would mean churn ran before cycle.
    expect($metrics->churn_risk_score)->not->toBeNull()
        ->and($metrics->churn_risk_level)->not->toBeNull();

    // lifecycle_stage is the last step; still the schema default would mean it never ran at all.
    expect(DB::table('customers')->where('id', $customer->id)->value('lifecycle_stage'))->not->toBe('prospect');
});

it('implements ShouldBeUnique so at most one recompute runs at a time', function () {
    expect(in_array(ShouldBeUnique::class, class_implements(RecomputeMetricsJob::class), true))->toBeTrue();
});
