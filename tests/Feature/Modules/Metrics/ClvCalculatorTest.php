<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\ClvCalculator;
use Illuminate\Support\Facades\DB;

/*
| P4-04 (TEST FIRST, PRD §13). clv_historical is always (total_revenue * margin_rate)::bigint — never
| NULL, since customer_metrics.clv_historical is NOT NULL DEFAULT 0 (P1-04 migration) and a customer
| with no orders naturally has total_revenue=0, so this needs no special-casing at all. clv_estimated
| and clv_confidence are the pair that can be NULL together ("insufficient data", never zero, never a
| confidence label with nothing to be confident about). margin_rate/horizon_years always come from
| config('metrics.*'), never a literal, so a store's real margin/horizon can change without a
| code change (CLAUDE.md §3/§12).
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

it('leaves clv_estimated (and clv_confidence) null for a single-order customer', function () {
    $customer = clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    $row = clvRow($customer);
    expect([$row->clv_estimated, $row->clv_confidence])->toBe([null, null]);
});

it('leaves clv_estimated null when purchase_cycle_days is zero, never divides by zero', function () {
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 0]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_estimated)->toBeNull();
});

it('leaves both clv_estimated and clv_confidence null when purchase_cycle_days is null', function () {
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => null]);

    app(ClvCalculator::class)->compute();

    $row = clvRow($customer);
    expect([$row->clv_estimated, $row->clv_confidence])->toBe([null, null]);
});

it('computes clv_estimated with the exact PRD §13 formula', function () {
    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 2.0]);
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    $expected = (int) round(500_000 * 0.17 * (365.0 / 90) * 2.0);
    expect(clvRow($customer)->clv_estimated)->toBe($expected);
});

it('scales clv_estimated with metrics.horizon_years from config', function () {
    $customer = clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    config(['metrics.margin_rate' => 0.17, 'metrics.horizon_years' => 1.0]);
    app(ClvCalculator::class)->compute();
    $atOneYear = clvRow($customer)->clv_estimated;

    config(['metrics.horizon_years' => 2.0]);
    app(ClvCalculator::class)->compute();
    $atTwoYears = clvRow($customer)->clv_estimated;

    expect($atTwoYears)->toBe($atOneYear * 2);
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

it('gives clv_confidence null, never \'low\', when clv_estimated is null', function () {
    $customer = clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    expect(clvRow($customer)->clv_confidence)->toBeNull();
});

// ================================================================== display contract

it('never leaves clv_confidence null while clv_estimated is set — the mandatory display pairing', function () {
    clvCustomer(['total_orders' => 0, 'total_revenue' => 0, 'aov' => 0]);
    clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);
    clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000, 'purchase_cycle_days' => 0]);
    clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);
    clvCustomer(['total_orders' => 6, 'total_revenue' => 3_000_000, 'aov' => 500_000, 'purchase_cycle_days' => 90]);

    app(ClvCalculator::class)->compute();

    expect(DB::table('customer_metrics')->whereNotNull('clv_estimated')->whereNull('clv_confidence')->count())->toBe(0);
});

// ================================================================== compute() return value

it('returns the number of customers with at least one order', function () {
    clvCustomer(['total_orders' => 1, 'total_revenue' => 500_000, 'aov' => 500_000]);
    clvCustomer(['total_orders' => 2, 'total_revenue' => 1_000_000, 'aov' => 500_000]);
    clvCustomer(['total_orders' => 3, 'total_revenue' => 1_500_000, 'aov' => 500_000]);
    clvCustomer(['total_orders' => 0, 'total_revenue' => 0, 'aov' => 0]);

    expect(app(ClvCalculator::class)->compute())->toBe(3);
});
