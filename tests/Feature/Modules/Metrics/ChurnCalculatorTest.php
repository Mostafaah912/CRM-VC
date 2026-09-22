<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\ChurnCalculator;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| P4-05 (TEST FIRST, PRD §14 steps 3-4). churn_reason is mandatory whenever churn_risk_level is set
| (CLAUDE.md §4) — a risk number without a reason nobody trusts it. Level is driven by recency_days
| against the store's real p75/p90 (never a hardcoded day count), never by the score itself. Rows are
| built directly against customer_metrics for score/level/reason isolation; the trend-penalty tests
| need real rows in `orders` since last_interval is read from there, not from customer_metrics.
*/

const CHURN_THRESHOLDS = ['p50' => 60, 'p75' => 120, 'p90' => 210];

function churnCustomer(array $metrics = []): Customer
{
    $customer = Customer::factory()->create($metrics['customer'] ?? []);
    unset($metrics['customer']);

    DB::table('customer_metrics')->insert(array_merge([
        'customer_id' => $customer->id,
        'total_orders' => 1,
    ], $metrics));

    return $customer;
}

function churnRow(Customer $customer): object
{
    return DB::table('customer_metrics')->where('customer_id', $customer->id)->first();
}

// ================================================================== prospects

it('leaves a prospect with all null churn fields', function () {
    $customer = churnCustomer([
        'total_orders' => 0, 'recency_days' => null,
        // stale values from a prior run (e.g. before a full refund dropped them back to 0 orders),
        // to prove the nullify step actively clears them rather than only ever seeing fresh NULLs.
        'churn_risk_score' => 80, 'churn_risk_level' => 'high', 'churn_reason' => 'قدیمی',
    ]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    $row = churnRow($customer);
    expect([$row->churn_risk_score, $row->churn_risk_level, $row->churn_reason, $row->expected_next_order_at])
        ->toBe([null, null, null, null]);
});

it('leaves expected_next_order_at null when there are no orders', function () {
    $customer = churnCustomer(['total_orders' => 0, 'recency_days' => null]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect(churnRow($customer)->expected_next_order_at)->toBeNull();
});

// ================================================================== base score

it('keeps two decimal places, never rounds to a whole integer', function () {
    // single-order penalty: base = 10/60*40 = 6.666... + 15 = 21.666... -> 21.67, never 22.
    $customer = churnCustomer(['total_orders' => 1, 'recency_days' => 10, 'purchase_cycle_days' => 60]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((float) churnRow($customer)->churn_risk_score)->toEqual(21.67);
});

it('gives base score 40 for a ratio of exactly 1', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 90, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(40);
});

it('caps the base score at 100 for a large ratio', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 300, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(100);
});

it('gives a low score to a very recently ordered customer', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 10, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe((int) round(10 / 90 * 40));
});

// ================================================================== penalties

it('adds a 15-point penalty for a single-order customer', function () {
    $customer = churnCustomer(['total_orders' => 1, 'recency_days' => 90, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(55);
});

it('adds a trend penalty when the last real interval exceeds 1.5x the cycle', function () {
    // cycle=90, so the trend threshold is 135 days; the last (most recent) gap here is 150 > 135.
    $customer = churnCustomer(['total_orders' => 3, 'recency_days' => 90, 'purchase_cycle_days' => 90]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(300)]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(200)]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(50)]); // last interval: 150

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(50); // 40 base + 10 trend
});

it('never adds the trend penalty for a two-order customer, however large the last interval', function () {
    $customer = churnCustomer(['total_orders' => 2, 'recency_days' => 90, 'purchase_cycle_days' => 90]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(300)]);
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()->subDays(50)]); // interval: 250, way over 1.5x

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(40);
});

it('subtracts a 5-point value dampener when m_score is 5', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 90, 'purchase_cycle_days' => 90, 'm_score' => 5]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(35);
});

// ================================================================== score bounds

it('never lets the score fall below 0', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 0, 'purchase_cycle_days' => 90, 'm_score' => 5]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(0);
});

it('never lets the score exceed 100 even after penalties', function () {
    $customer = churnCustomer(['total_orders' => 1, 'recency_days' => 1000, 'purchase_cycle_days' => 1]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect((int) churnRow($customer)->churn_risk_score)->toBe(100);
});

// ================================================================== level

it('gives level lost when recency exceeds p90 times 2', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 421, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect(churnRow($customer)->churn_risk_level)->toBe('lost');
});

it('gives level high when recency exceeds p90 but not p90 times 2', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 211, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect(churnRow($customer)->churn_risk_level)->toBe('high');
});

it('gives level medium when recency exceeds p75 but not p90', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 121, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect(churnRow($customer)->churn_risk_level)->toBe('medium');
});

it('gives level low to a recently ordered customer', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 30, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect(churnRow($customer)->churn_risk_level)->toBe('low');
});

// ================================================================== churn_reason (Persian, mandatory)

it('includes recency_days, purchase_cycle_days and the p75 threshold in churn_reason', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 45, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    $reason = churnRow($customer)->churn_reason;
    expect($reason)->toContain('45')->toContain('90')->toContain('120');
});

it('matches the exact PRD §14 Persian reason format', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 45, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect(churnRow($customer)->churn_reason)
        ->toBe('45 روز از آخرین خرید گذشته؛ چرخه خرید این مشتری 90 روز است (آستانه فروشگاه: 120 روز)');
});

it('never puts a customer\'s name or phone inside churn_reason', function () {
    $customer = churnCustomer(['total_orders' => 5, 'recency_days' => 45, 'purchase_cycle_days' => 90]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    $reason = churnRow($customer)->churn_reason;
    expect($reason)->not->toContain($customer->display_name)
        ->and($reason)->not->toContain($customer->phone_normalized);
});

// ================================================================== expected_next_order_at

it('sets expected_next_order_at to last_order_at plus the purchase cycle', function () {
    $customer = churnCustomer([
        'total_orders' => 5, 'recency_days' => 30, 'purchase_cycle_days' => 30,
        'last_order_at' => Carbon::parse('2025-01-01 00:00:00', 'UTC'),
    ]);

    app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS);

    expect(Carbon::parse(churnRow($customer)->expected_next_order_at)->equalTo(Carbon::parse('2025-01-31 00:00:00', 'UTC')))->toBeTrue();
});

// ================================================================== compute() return value

it('returns the number of customers with at least one order', function () {
    churnCustomer(['total_orders' => 1, 'recency_days' => 10, 'purchase_cycle_days' => 30]);
    churnCustomer(['total_orders' => 2, 'recency_days' => 20, 'purchase_cycle_days' => 30]);
    churnCustomer(['total_orders' => 3, 'recency_days' => 30, 'purchase_cycle_days' => 30]);
    churnCustomer(['total_orders' => 0, 'recency_days' => null]);
    $deleted = churnCustomer(['total_orders' => 4, 'recency_days' => 40, 'purchase_cycle_days' => 30]);
    $deleted->delete();

    expect(app(ChurnCalculator::class)->compute(CHURN_THRESHOLDS))->toBe(3);
});
