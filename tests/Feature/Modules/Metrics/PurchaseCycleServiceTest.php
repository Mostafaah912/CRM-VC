<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Services\BaseAggregateService;
use App\Modules\Metrics\Services\MetricRunService;
use App\Modules\Metrics\Services\PurchaseCycleService;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/*
| P4-02 (TEST FIRST): purchase_cycle_days = COALESCE(personal_median_days_between, store_p50), PRD
| §14 step 2. Median (not mean) so one outlier gap never skews a customer's whole cycle. Assumes
| BaseAggregateService has already given every customer a customer_metrics row (PRD §11 step 3
| runs before step 4), so every test seeds that row first.
*/

const STORE_THRESHOLDS = ['p50' => 60, 'p75' => 120, 'p90' => 210, 'sample_size' => 0];

function seedBaseMetrics(): void
{
    app(BaseAggregateService::class)->computeAll(app(MetricRunService::class)->start(MetricRunMode::Full)->id);
}

function purchaseCycleFor(Customer $customer): ?float
{
    $value = DB::table('customer_metrics')->where('customer_id', $customer->id)->value('purchase_cycle_days');

    return $value === null ? null : (float) $value;
}

it('gives a single-order customer the store p50', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true]);
    seedBaseMetrics();

    app(PurchaseCycleService::class)->compute(STORE_THRESHOLDS);

    expect(purchaseCycleFor($customer))->toBe(60.0);
});

it('gives a multi-order customer their own median interval, not the store p50', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(90)]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(60)]); // interval 30
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(0)]); // interval 60
    seedBaseMetrics();

    app(PurchaseCycleService::class)->compute(STORE_THRESHOLDS);

    expect(purchaseCycleFor($customer))->toBe(45.0);
});

it('never counts a fully refunded order towards a customer\'s interval', function () {
    $customer = Customer::factory()->create();
    // Without exclusion the median would be 30 (90->60, 60->30); with it, it's 60 (90->30 alone).
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(90)]);
    Order::factory()->for($customer)->create([
        'is_realized' => true, 'is_fully_refunded' => true, 'ordered_at' => now()->subDays(60),
    ]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(30)]);
    seedBaseMetrics();

    app(PurchaseCycleService::class)->compute(STORE_THRESHOLDS);

    expect(purchaseCycleFor($customer))->toBe(60.0);
});

it('never counts an interval longer than 730 days towards a customer\'s median', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(900)]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(30)]);
    seedBaseMetrics();

    app(PurchaseCycleService::class)->compute(STORE_THRESHOLDS);

    expect(purchaseCycleFor($customer))->toBe(60.0);
});

it('leaves a prospect\'s purchase_cycle_days null: they have no interval to measure', function () {
    $customer = Customer::factory()->create();
    seedBaseMetrics();

    app(PurchaseCycleService::class)->compute(STORE_THRESHOLDS);

    expect(purchaseCycleFor($customer))->toBeNull();
});

it('never counts an unrealized order towards a customer\'s interval', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => false]);
    seedBaseMetrics();

    app(PurchaseCycleService::class)->compute(STORE_THRESHOLDS);

    expect(purchaseCycleFor($customer))->toBeNull();
});

it('returns the number of customers with at least one realized order', function () {
    $withOrders = Customer::factory()->count(3)->create();
    foreach ($withOrders as $customer) {
        Order::factory()->for($customer)->create(['is_realized' => true]);
    }
    Customer::factory()->create(); // prospect, no orders
    seedBaseMetrics();

    expect(app(PurchaseCycleService::class)->compute(STORE_THRESHOLDS))->toBe(3);
});
