<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\ClvCalculator;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/*
| P4-04 (TEST FIRST, PRD §13). clv_historical is always (total_revenue * margin_rate)::bigint — never
| NULL, since customer_metrics.clv_historical is NOT NULL DEFAULT 0 (P1-04 migration) and a customer
| with no orders naturally has total_revenue=0, so this needs no special-casing at all.
|
| clv_confidence is set from total_orders alone, for every customer with total_orders >= 1 —
| INDEPENDENTLY of clv_estimated's nullability. A single-order customer legitimately gets 'low'
| confidence even though clv_estimated is NULL (not enough orders to project from): confidence
| here means "how much history do we have," a fact regardless of whether that history is enough to
| project a number. Gate 2 (tests/fixtures/expected_metrics.json) is what proved this — an earlier
| version of this file paired both columns under one shared NULL condition, which Gate 2's real
| pipeline run against the frozen demo dataset showed was wrong (see ARCHITECTURE.md P4-08).
|
| margin_rate/horizon_years always come from config('metrics.*'), never a literal, so a store's real
| margin/horizon can change without a code change (CLAUDE.md §3/§12).
*/

function clvCustomer(array $metrics = []): Customer
{
    $customer = Customer::factory()->create($metrics['customer'] ?? []);
    unset($metrics['customer']);

    DB::table('customer_metrics')->insert(array_merge([
        'customer_id' => $customer->id,
        'total_orders' => 1,
        'total_revenue' => 0,
        'aov' => 0,
    ], $metrics));

    return $customer;
}

/** Realized, not-refunded orders at the given instants (UTC strings) — clv_estimated is derived from the orders' distinct purchase DAYS. */
function clvOrders(Customer $customer, array $at, array $overrides = []): void
{
    foreach ($at as $when) {
        Order::factory()->create(array_merge(['customer_id' => $customer->id, 'is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => $when], $overrides));
    }
}

function clvRow(Customer $customer): object
{
    return DB::table('customer_metrics')->where('customer_id', $customer->id)->first();
}

// ================================================================== clv_historical

it('leaves a prospect\'s clv_historical at 0 (the column is NOT NULL — see ARCHITECTURE.md P4-04)', function () {
    $customer = clvCustomer(['total_orders' => 0, 'total_revenue' => 0, 'aov' => 0]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_historical)->toBe(0);
});

it('computes clv_historical from total_revenue using metrics.margin_rate from config', function () {
    $customer = clvCustomer(['total_orders' => 1, 'total_revenue' => 1_000_000, 'aov' => 1_000_000]);

    config(['metrics.margin_rate' => 0.17]);
    app(ClvCalculator::class)->compute();
    expect(clvRow($customer)->clv_historical)->toBe(170_000);

    config(['metrics.margin_rate' => 0.20]);
    app(ClvCalculator::class)->compute();
    expect(clvRow($customer)->clv_historical)->toBe(200_000);
});

it('rounds clv_historical to an integer, matching Postgres\'s numeric-to-bigint cast', function () {
    config(['metrics.margin_rate' => 0.17]);
    $customer = clvCustomer(['total_orders' => 1, 'total_revenue' => 1_000_001, 'aov' => 1_000_001]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_historical)->toBe((int) round(1_000_001 * 0.17));
});

// ================================================================== clv_estimated

it('leaves clv_estimated null for a single-order customer, but still gives a real clv_confidence', function () {
    $customer = clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    $row = clvRow($customer);
    expect($row->clv_estimated)->toBeNull()
        ->and($row->clv_confidence)->toBe('low');
});

it('computes clv_estimated from the median gap between DISTINCT PURCHASE DAYS (P8 amendment of PRD §13), not from purchase_cycle_days', function () {
    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 2.0, 'metrics.clv.cap_multiple' => 1000.0]);
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 0.01]);
    clvOrders($customer, ['2026-01-01 09:00:00', '2026-03-02 09:00:00', '2026-05-01 09:00:00']);   // gaps 60 and 60 days

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_estimated)->toBe((int) round(500_000 * 0.17 * (365.0 / 60) * 2.0));
});

it('scales clv_estimated with metrics.horizon_years from config', function () {
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000]);
    clvOrders($customer, ['2026-01-01 09:00:00', '2026-03-02 09:00:00', '2026-05-01 09:00:00']);

    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 1.0, 'metrics.clv.cap_multiple' => 1000.0]);
    app(ClvCalculator::class)->compute();
    $atOneYear = clvRow($customer)->clv_estimated;

    config(['metrics.horizon_years' => 2.0]);
    app(ClvCalculator::class)->compute();
    $atTwoYears = clvRow($customer)->clv_estimated;

    expect(abs($atTwoYears - $atOneYear * 2))->toBeLessThanOrEqual(1);   // each is rounded to a whole Toman separately
});

it('treats orders minutes apart on the same day as ONE purchase: no cycle, no estimate (the 0.01-day-cycle bug: a 5.7 bn projection for a 1.8 m customer)', function () {
    $customer = clvCustomer(['total_orders' => 2, 'total_revenue' => 1_846_750, 'aov' => 923_375, 'purchase_cycle_days' => 0.02]);
    clvOrders($customer, ['2026-04-01 09:00:00', '2026-04-01 09:07:00']);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_estimated)->toBeNull()->and(clvRow($customer)->clv_confidence)->toBe('low');
});

it('counts a same-day pair as one purchase day: a pair plus one later order has a 30-day cycle, not a minutes-long one', function () {
    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 2.0, 'metrics.clv.cap_multiple' => 1000.0]);
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000]);
    clvOrders($customer, ['2026-04-01 09:00:00', '2026-04-01 09:07:00', '2026-05-01 09:00:00']);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_estimated)->toBe((int) round(500_000 * 0.17 * (365.0 / 30) * 2.0));
});

it('uses Asia/Tehran dates for purchase days: 23:50 and 00:30 Tehran time are two days even though both are the same UTC date', function () {
    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 2.0, 'metrics.clv.cap_multiple' => 1000.0]);
    $customer = clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000]);
    clvOrders($customer, ['2026-04-01 20:20:00', '2026-04-01 21:00:00']);   // 23:50 Apr 1 and 00:30 Apr 2 in Tehran (UTC+3:30)

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_estimated)->toBe((int) round(500_000 * 0.17 * (365.0 / 1) * 2.0));   // a 1-day cycle is the floor
});

it('requires metrics.clv.min_purchase_days distinct purchase days', function () {
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000]);
    clvOrders($customer, ['2026-01-01 09:00:00', '2026-03-02 09:00:00', '2026-05-01 09:00:00']);

    config(['metrics.clv.min_purchase_days' => 4, 'metrics.clv.cap_multiple' => 1000.0]);
    app(ClvCalculator::class)->compute();
    expect(clvRow($customer)->clv_estimated)->toBeNull();

    config(['metrics.clv.min_purchase_days' => 3]);
    app(ClvCalculator::class)->compute();
    expect(clvRow($customer)->clv_estimated)->not->toBeNull();
});

it('caps clv_estimated at metrics.clv.cap_multiple x clv_historical (default 3x), and never above', function () {
    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 2.0]);
    // aov 500,000 every 30 days, 2 orders: the raw projection (~2.07 m) is far above 3 x clv_historical (3 x 170,000 = 510,000)
    $customer = clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000]);
    clvOrders($customer, ['2026-04-01 09:00:00', '2026-05-01 09:00:00']);

    app(ClvCalculator::class)->compute();
    expect(clvRow($customer)->clv_estimated)->toBe(3 * 170_000);

    config(['metrics.clv.cap_multiple' => 5.0]);
    app(ClvCalculator::class)->compute();
    expect(clvRow($customer)->clv_estimated)->toBe(5 * 170_000);
});

it('leaves a projection under the cap untouched', function () {
    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 2.0]);
    // 2 orders 700 days apart: raw = 500,000 x 0.17 x (365/700) x 2 = ~88,643 < 3 x 170,000
    $customer = clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000]);
    clvOrders($customer, ['2024-04-01 09:00:00', '2026-03-02 09:00:00']);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_estimated)->toBe((int) round(500_000 * 0.17 * (365.0 / 700) * 2.0));
});

it('ignores orders that are not realized, fully refunded or deleted when counting purchase days', function () {
    config(['metrics.clv.cap_multiple' => 1000.0]);
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000]);
    clvOrders($customer, ['2026-01-01 09:00:00']);
    clvOrders($customer, ['2026-02-01 09:00:00'], ['is_realized' => false]);
    clvOrders($customer, ['2026-03-01 09:00:00'], ['is_fully_refunded' => true]);
    clvOrders($customer, ['2026-04-01 09:00:00'], ['deleted_at' => now()]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_estimated)->toBeNull();   // only ONE counted purchase day
});

// ================================================================== clv_confidence

it('gives clv_confidence low for a two-order customer', function () {
    $customer = clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_confidence)->toBe('low');
});

it('gives clv_confidence medium for three to five orders', function () {
    $three = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);
    $five = clvCustomer(['total_orders' => 5, 'total_revenue' => 2_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($three)->clv_confidence)->toBe('medium')
        ->and(clvRow($five)->clv_confidence)->toBe('medium');
});

it('gives clv_confidence high for six or more orders', function () {
    $six = clvCustomer(['total_orders' => 6, 'total_revenue' => 3_000_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);
    $ten = clvCustomer(['total_orders' => 10, 'total_revenue' => 5_000_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($six)->clv_confidence)->toBe('high')
        ->and(clvRow($ten)->clv_confidence)->toBe('high');
});

it('gives clv_confidence low even though clv_estimated is null for a single-order customer', function () {
    $customer = clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_confidence)->toBe('low');
});

// ================================================================== confidence's own nullability

it('leaves clv_confidence null only for a prospect (total_orders=0), regardless of clv_estimated elsewhere', function () {
    clvCustomer(['total_orders' => 0, 'total_revenue' => 0, 'aov' => 0]);
    clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);
    clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000, 'purchase_cycle_days' => 0]);
    clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);
    clvCustomer(['total_orders' => 6, 'total_revenue' => 3_000_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    expect(DB::table('customer_metrics')->where('total_orders', '>=', 1)->whereNull('clv_confidence')->count())->toBe(0)
        ->and(DB::table('customer_metrics')->where('total_orders', 0)->whereNotNull('clv_confidence')->count())->toBe(0);
});

// ================================================================== compute() return value

it('returns the number of customers with at least one order', function () {
    clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000]);
    clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000]);
    clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000]);
    clvCustomer(['total_orders' => 0, 'total_revenue' => 0, 'aov' => 0]);

    expect(app(ClvCalculator::class)->compute())->toBe(3);
});
