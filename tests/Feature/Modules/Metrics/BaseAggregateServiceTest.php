<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Services\BaseAggregateService;
use App\Modules\Metrics\Services\MetricRunService;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
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

// ================================================================== P6-20: monetary_recent (TEST FIRST)

it('sums only realized orders within the window into monetary_recent, excluding an older one', function () {
    config(['metrics.monetary.window_days' => 60]);
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'subtotal' => 300_000, 'total' => 300_000, 'ordered_at' => $asOf->subDays(10)]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'subtotal' => 200_000, 'total' => 200_000, 'ordered_at' => $asOf->subDays(200)]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    $row = metricsRowFor($customer);
    expect($row->monetary)->toBe(500_000)
        ->and($row->monetary_recent)->toBe(300_000);
});

it('leaves monetary_recent null for a customer whose only orders are all outside the window', function () {
    config(['metrics.monetary.window_days' => 60]);
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'total' => 900_000, 'ordered_at' => $asOf->subDays(200)]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    $row = metricsRowFor($customer);
    expect($row->monetary)->toBe(900_000)
        ->and($row->monetary_recent)->toBeNull();
});

it('excludes shipping from monetary_recent via the goods-value base (subtotal - discount), not a shipping subtraction', function () {
    config(['metrics.include_shipping' => false, 'metrics.monetary.window_days' => 60]);
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'subtotal' => 450_000, 'shipping_total' => 50_000, 'total' => 500_000, 'ordered_at' => $asOf->subDay(),
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    expect(metricsRowFor($customer)->monetary_recent)->toBe(450_000);
});

it('includes an order exactly at the Tehran-day window boundary and excludes one from the moment before (the day before)', function () {
    config(['metrics.monetary.window_days' => 60]);
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    // The real boundary this test proves the implementation actually uses: Tehran midnight, 60 Tehran-calendar-days
    // before asOf's own Tehran calendar day — not a naive UTC subDays(60), and not a raw 60*86400-second subtraction.
    $windowStart = $asOf->setTimezone('Asia/Tehran')->subDays(60)->startOfDay();

    // Eloquent's datetime cast formats a Carbon attribute in whatever timezone it is currently set to,
    // without first converting it to UTC — handing it a Asia/Tehran-zoned instant directly would silently
    // drop the +03:30 offset and store the wrong absolute instant. ->utc() first avoids that pitfall;
    // BaseAggregateService itself never hits it, since it binds its own `?::timestamptz` with an explicit
    // offset in the formatted string, never through an Eloquent attribute.
    $included = Customer::factory()->create();
    Order::factory()->for($included)->create(['is_realized' => true, 'subtotal' => 111_000, 'total' => 111_000, 'ordered_at' => $windowStart->utc()]);

    $excluded = Customer::factory()->create();
    Order::factory()->for($excluded)->create(['is_realized' => true, 'subtotal' => 222_000, 'total' => 222_000, 'ordered_at' => $windowStart->subSecond()->utc()]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    expect(metricsRowFor($included)->monetary_recent)->toBe(111_000)
        ->and(metricsRowFor($excluded)->monetary_recent)->toBeNull();
});

it('gives the same monetary_recent on a repeated full recompute (idempotent)', function () {
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'subtotal' => 650_000, 'total' => 650_000, 'ordered_at' => $asOf->subDays(5)]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);
    $first = metricsRowFor($customer)->monetary_recent;

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);
    $second = metricsRowFor($customer)->monetary_recent;

    expect($second)->toBe($first)->toBe(650_000);
});

// ================================================================== P6-21: monetary_recent = goods value, no shipping, no tax, proportional refund

it('excludes tax from monetary_recent — goods value after discount only, unlike monetary (which still includes tax via net_revenue - shipping)', function () {
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'ordered_at' => $asOf->subDay(),
        'subtotal' => 500_000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 50_000,
        'total' => 550_000, 'refunded_total' => 0,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    $row = metricsRowFor($customer);
    expect($row->monetary)->toBe(550_000) // unchanged legacy formula: net_revenue - shipping = (550000-0) - 0, still includes tax
        ->and($row->monetary_recent)->toBe(500_000); // new formula: subtotal - discount, tax excluded
});

it('excludes shipping from monetary_recent via the goods-value formula, regardless of metrics.include_shipping', function () {
    config(['metrics.include_shipping' => true]); // even if the legacy monetary toggle says "include it"
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'ordered_at' => $asOf->subDay(),
        'subtotal' => 500_000, 'discount_total' => 0, 'shipping_total' => 100_000, 'tax_total' => 0,
        'total' => 600_000, 'refunded_total' => 0,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    expect(metricsRowFor($customer)->monetary_recent)->toBe(500_000);
});

it('applies the discount to monetary_recent — goods value is after discount, not the gross subtotal', function () {
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'ordered_at' => $asOf->subDay(),
        'subtotal' => 1_000_000, 'discount_total' => 200_000, 'shipping_total' => 0, 'tax_total' => 0,
        'total' => 800_000, 'refunded_total' => 0,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    expect(metricsRowFor($customer)->monetary_recent)->toBe(800_000);
});

it('reduces monetary_recent proportionally for a partial refund, not by the full refunded amount', function () {
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    // total=1,000,000, refunded=250,000 -> refund_ratio=0.25 -> goods value (1,000,000) * 0.75 = 750,000.
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => $asOf->subDay(),
        'subtotal' => 1_000_000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0,
        'total' => 1_000_000, 'refunded_total' => 250_000,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    expect(metricsRowFor($customer)->monetary_recent)->toBe(750_000);
});

it('combines discount, shipping, tax and a partial refund correctly in monetary_recent', function () {
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    // subtotal=1,000,000 discount=100,000 shipping=50,000 tax=30,000 -> total=980,000.
    // refunded=98,000 -> refund_ratio=0.1 -> goods value (900,000) * 0.9 = 810,000.
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => $asOf->subDay(),
        'subtotal' => 1_000_000, 'discount_total' => 100_000, 'shipping_total' => 50_000, 'tax_total' => 30_000,
        'total' => 980_000, 'refunded_total' => 98_000,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    expect(metricsRowFor($customer)->monetary_recent)->toBe(810_000);
});

it('never divides by zero when an order total is 0 — treats the refund ratio as 0', function () {
    $asOf = CarbonImmutable::parse('2026-06-30 08:30:00', 'UTC');
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => $asOf->subDay(),
        'subtotal' => 0, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0,
        'total' => 0, 'refunded_total' => 0,
    ]);

    app(BaseAggregateService::class)->computeAll(metricRunId(), $asOf);

    expect(metricsRowFor($customer)->monetary_recent)->toBe(0);
});

it('returns the number of customers processed', function () {
    Customer::factory()->count(3)->create();

    expect(app(BaseAggregateService::class)->computeAll(metricRunId()))->toBe(3);
});

it('resetDirtyFlag clears metrics_dirty only for customers touched by the given metric run', function () {
    $processed = Customer::factory()->create(['metrics_dirty' => true]);
    $runId = metricRunId();
    DB::table('customer_metrics')->insert([
        'customer_id' => $processed->id,
        'total_orders' => 0,
        'metric_run_id' => $runId,
    ]);

    $otherRunId = metricRunId();
    $untouched = Customer::factory()->create(['metrics_dirty' => true]);
    DB::table('customer_metrics')->insert([
        'customer_id' => $untouched->id,
        'total_orders' => 0,
        'metric_run_id' => $otherRunId,
    ]);

    $affected = app(BaseAggregateService::class)->resetDirtyFlag($runId);

    expect($affected)->toBe(1)
        ->and($processed->refresh()->metrics_dirty)->toBeFalse()
        ->and($untouched->refresh()->metrics_dirty)->toBeTrue();
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
