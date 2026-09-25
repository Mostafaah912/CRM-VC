<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Services\ChurnThresholdService;
use App\Modules\Metrics\Services\MetricRunService;
use App\Modules\Orders\Models\Order;

/*
| P4-02 (TEST FIRST): the store's real p50/p75/p90 of days between a customer's realized orders,
| PRD §14 step 1. Real PostgreSQL only — percentile_cont and the LAG window function have no SQLite
| equivalent. Below 200 qualifying intervals, config('metrics.fallback_percentiles') stands in
| rather than a threshold computed from a handful of unrepresentative orders.
*/

function ordersFor(Customer $customer, array $daysAgo, array $overrides = []): void
{
    foreach ($daysAgo as $days) {
        Order::factory()->for($customer)->create(array_merge([
            'is_realized' => true,
            'ordered_at' => now()->subDays($days),
        ], $overrides));
    }
}

it('returns the configured fallback when the sample is smaller than 200 intervals', function () {
    $thresholds = app(ChurnThresholdService::class)->percentiles();

    expect($thresholds['sample_size'])->toBeLessThan(200)
        ->and($thresholds)->toBe([
            'p50' => 60,
            'p75' => 120,
            'p90' => 210,
            'sample_size' => $thresholds['sample_size'],
        ]);
});

it('reads the fallback values from config, not a hardcoded number', function () {
    config(['metrics.fallback_percentiles' => ['p50' => 61, 'p75' => 130, 'p90' => 200]]);

    $thresholds = app(ChurnThresholdService::class)->percentiles();

    expect([$thresholds['p50'], $thresholds['p75'], $thresholds['p90']])->toBe([61, 130, 200]);
});

it('excludes an unrealized order from the interval count', function () {
    $customer = Customer::factory()->create();
    ordersFor($customer, [60, 30]); // one realized interval: 30 days
    Order::factory()->for($customer)->create(['is_realized' => false, 'ordered_at' => now()]);

    expect(app(ChurnThresholdService::class)->percentiles()['sample_size'])->toBe(1);
});

it('excludes a fully refunded order from the interval count', function () {
    $customer = Customer::factory()->create();
    // Without exclusion this would be 2 intervals (90->60, 60->30); with it, 1 (90->30).
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(90)]);
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'is_fully_refunded' => true, 'ordered_at' => now()->subDays(60),
    ]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(30)]);

    expect(app(ChurnThresholdService::class)->percentiles()['sample_size'])->toBe(1);
});

it('excludes an interval longer than 730 days', function () {
    $customer = Customer::factory()->create();
    ordersFor($customer, [800, 0]);

    expect(app(ChurnThresholdService::class)->percentiles()['sample_size'])->toBe(0);
});

it('saves the computed thresholds onto the metric run', function () {
    $run = app(MetricRunService::class)->start(MetricRunMode::Full);
    $service = app(ChurnThresholdService::class);

    $service->saveToRun($run, $service->percentiles());

    $run->refresh();
    expect($run->thresholds)->toBe(['p50' => 60, 'p75' => 120, 'p90' => 210, 'sample_size' => 0]);
});
