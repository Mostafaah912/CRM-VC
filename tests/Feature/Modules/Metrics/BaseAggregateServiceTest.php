<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Services\BaseAggregateService;
use App\Modules\Metrics\Services\MetricRunService;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/*
| P4-01 (TEST FIRST): the base-aggregate UPSERT, PRD §11 step 3. Real PostgreSQL only — NTILE-adjacent
| aggregate math (EXTRACT(EPOCH ...), the LATERAL join, to_jalali_month) has no SQLite equivalent.
| Only realized, not-fully-refunded, non-deleted orders of a non-deleted customer ever count.
*/

function metricRunId(): int
{
    return app(MetricRunService::class)->start(MetricRunMode::Full)->id;
}

function metricsRowFor(Customer $customer): object
{
    return DB::table('customer_metrics')->where('customer_id', $customer->id)->first();
}

it('gives a prospect with no orders zero totals, a zero aov and a null recency', function () {
    $customer = Customer::factory()->create();

    app(BaseAggregateService::class)->computeAll(metricRunId());

    $row = metricsRowFor($customer);
    expect($row->total_orders)->toBe(0)
        ->and($row->frequency)->toBe(0)
        ->and($row->total_revenue)->toBe(0)
        ->and($row->monetary)->toBe(0)
        ->and($row->aov)->toBe(0)
        ->and($row->recency_days)->toBeNull()
        ->and($row->first_order_at)->toBeNull()
        ->and($row->last_order_at)->toBeNull()
        ->and($row->cohort_month)->toBeNull();
});

it('sets first/last order, total_orders, frequency and revenue from a single realized order', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true,
        'total' => 500_000,
        'ordered_at' => now()->subDay(),
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId());

    $row = metricsRowFor($customer);
    expect($row->total_orders)->toBe(1)
        ->and($row->frequency)->toBe(1)
        ->and($row->total_revenue)->toBe(500_000)
        ->and($row->monetary)->toBe(500_000)
        ->and($row->aov)->toBe(500_000)
        ->and($row->first_order_at)->not->toBeNull()
        ->and($row->first_order_at)->toBe($row->last_order_at);
});

it('excludes shipping from monetary by default (PRD D2), while total_revenue keeps it', function () {
    config(['metrics.include_shipping' => false]);
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true,
        'total' => 500_000,
        'shipping_total' => 50_000,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId());

    $row = metricsRowFor($customer);
    expect($row->total_revenue)->toBe(500_000)
        ->and($row->monetary)->toBe(450_000);
});

it('keeps shipping in monetary when metrics.include_shipping is true', function () {
    config(['metrics.include_shipping' => true]);
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true,
        'total' => 500_000,
        'shipping_total' => 50_000,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId());

    expect(metricsRowFor($customer)->monetary)->toBe(500_000);
});

it('excludes an unrealized order', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => false, 'total' => 500_000]);

    app(BaseAggregateService::class)->computeAll(metricRunId());

    expect(metricsRowFor($customer)->total_orders)->toBe(0);
});

it('excludes a fully refunded order', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true,
        'is_fully_refunded' => true,
        'total' => 500_000,
        'refunded_total' => 500_000,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId());

    expect(metricsRowFor($customer)->total_orders)->toBe(0);
});

it('excludes a soft-deleted order', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->create(['is_realized' => true, 'total' => 500_000]);
    $order->delete();

    app(BaseAggregateService::class)->computeAll(metricRunId());

    expect(metricsRowFor($customer)->total_orders)->toBe(0);
});

it('gives an older last order a larger recency_days than a recent one', function () {
    $recent = Customer::factory()->create();
    Order::factory()->for($recent)->create(['is_realized' => true, 'ordered_at' => now()->subDay()]);

    $old = Customer::factory()->create();
    Order::factory()->for($old)->create(['is_realized' => true, 'ordered_at' => now()->subDays(30)]);

    app(BaseAggregateService::class)->computeAll(metricRunId());

    expect(metricsRowFor($old)->recency_days)->toBeGreaterThan(metricsRowFor($recent)->recency_days);
});

it('upserts over an existing customer_metrics row rather than duplicating it', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 999]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'total' => 500_000]);

    app(BaseAggregateService::class)->computeAll(metricRunId());

    expect(DB::table('customer_metrics')->where('customer_id', $customer->id)->count())->toBe(1)
        ->and(metricsRowFor($customer)->total_orders)->toBe(1);
});

it('excludes a soft-deleted customer entirely', function () {
    $customer = Customer::factory()->create();
    $customer->delete();

    app(BaseAggregateService::class)->computeAll(metricRunId());

    expect(DB::table('customer_metrics')->where('customer_id', $customer->id)->exists())->toBeFalse();
});

it('recomputes only customers.metrics_dirty = true customers when run dirty-only', function () {
    $dirty = Customer::factory()->create(['metrics_dirty' => true]);
    $clean = Customer::factory()->create(['metrics_dirty' => false]);
    Order::factory()->for($dirty)->create(['is_realized' => true, 'total' => 500_000]);
    Order::factory()->for($clean)->create(['is_realized' => true, 'total' => 700_000]);
    DB::table('customer_metrics')->insert(['customer_id' => $clean->id, 'total_orders' => 999]);

    app(BaseAggregateService::class)->computeDirty(metricRunId());

    expect(metricsRowFor($dirty)->total_orders)->toBe(1)
        ->and(metricsRowFor($clean)->total_orders)->toBe(999);
});

it('returns the number of customers processed', function () {
    Customer::factory()->count(3)->create();

    expect(app(BaseAggregateService::class)->computeAll(metricRunId()))->toBe(3);
});

it('runs a metric_runs row through its full lifecycle: start, finish and fail', function () {
    $service = app(MetricRunService::class);

    $run = $service->start(MetricRunMode::Full);
    expect($run->status)->toBe(MetricRunStatus::Running)->and($run->finished_at)->toBeNull();

    $service->finish($run, 42);
    $run->refresh();
    expect($run->status)->toBe(MetricRunStatus::Completed)
        ->and($run->customers_processed)->toBe(42)
        ->and($run->finished_at)->not->toBeNull();

    $failedRun = $service->start(MetricRunMode::Dirty);
    $service->fail($failedRun, 'boom');
    $failedRun->refresh();
    expect($failedRun->status)->toBe(MetricRunStatus::Failed)
        ->and($failedRun->error)->toBe('boom')
        ->and($failedRun->finished_at)->not->toBeNull();
});
