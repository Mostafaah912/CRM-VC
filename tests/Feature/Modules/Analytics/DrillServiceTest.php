<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\DrillService;
use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| P6-07/P6-08 (TEST FIRST): PRD Sec.18's "هیچ عددی که پشتش دیده نشود قابل اعتماد نیست" — one uniform
| GET /internal/drill/{widget} behind it, DrillService::rows(). orders/customers_new/customers_repeat/
| rfm_segment/churn_level shipped in P6-07; cohort_period/affinity_pair (product/category/variation only
| -- basket is a different row shape, still deferred) were added in P6-08 once the Cohort/Affinity pages
| made the exact use case concrete. Rows never carry a name or phone (same PII-minimal rule as
| RfmPageService::topChampions()) -- only the audited CSV export (DrillExportTest) may include identity
| columns.
*/

function drillPeriod(string $from, string $to): DashboardPeriod
{
    return DashboardPeriod::fromDates(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
}

function realizedOrder(Customer $customer, string $orderedAt, array $overrides = []): Order
{
    return Order::factory()->for($customer)->create(array_merge([
        'is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => $orderedAt, 'total' => 100_000,
    ], $overrides));
}

it('lists orders realized within the period, none outside it', function () {
    $customer = Customer::factory()->create();
    realizedOrder($customer, '2026-06-01 10:00:00');
    realizedOrder($customer, '2026-06-02 10:00:00');
    realizedOrder($customer, '2026-05-31 10:00:00'); // outside

    $result = app(DrillService::class)->rows('orders', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result)->not->toBeNull()->and($result->rows)->toHaveCount(2)->and($result->truncated)->toBeFalse();
});

/* P6-14 phase 3: the orders widget's revenue breakdown, same convention as the order detail page
 * (RevenueBreakdown): product_revenue = subtotal - discount_total, shipping_revenue = shipping_total. */
it('includes product_revenue/shipping_revenue/refunded_total alongside total/net_revenue', function () {
    $customer = Customer::factory()->create();
    realizedOrder($customer, '2026-06-01 10:00:00', [
        'total' => 500_000, 'subtotal' => 480_000, 'discount_total' => 30_000, 'shipping_total' => 50_000, 'refunded_total' => 20_000,
    ]);

    $result = app(DrillService::class)->rows('orders', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->columns)->toBe(['order_id', 'customer_id', 'ordered_at', 'product_revenue', 'shipping_revenue', 'total', 'refunded_total', 'net_revenue']);
    $row = $result->rows[0];
    expect($row['product_revenue'])->toBe(450_000) // 480,000 - 30,000
        ->and($row['shipping_revenue'])->toBe(50_000)
        ->and($row['total'])->toBe(500_000)
        ->and($row['refunded_total'])->toBe(20_000)
        ->and($row['net_revenue'])->toBe(480_000); // 500,000 - 20,000 (generated column)
});

it('excludes a non-realized or fully-refunded order from the orders widget', function () {
    $customer = Customer::factory()->create();
    realizedOrder($customer, '2026-06-01 10:00:00', ['is_realized' => false]);
    realizedOrder($customer, '2026-06-01 11:00:00', ['is_fully_refunded' => true]);

    $result = app(DrillService::class)->rows('orders', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows)->toHaveCount(0);
});

it('never includes a display_name or phone column in the orders widget', function () {
    $customer = Customer::factory()->create();
    realizedOrder($customer, '2026-06-01 10:00:00');

    $result = app(DrillService::class)->rows('orders', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->columns)->not->toContain('display_name')->not->toContain('phone')->not->toContain('phone_normalized');
});

it('shows the Woo-facing order number (woo_order_id) as order_id, never the internal row id (P6-12)', function () {
    $customer = Customer::factory()->create();
    $order = realizedOrder($customer, '2026-06-01 10:00:00', ['woo_order_id' => 918_273]);

    $result = app(DrillService::class)->rows('orders', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows[0]['order_id'])->toBe(918_273)
        ->and($result->rows[0]['order_id'])->not->toBe($order->id);
});

it('formats the orders widget\'s ordered_at as Jalali/Tehran time (P6-11), never the raw Gregorian value', function () {
    $customer = Customer::factory()->create();
    realizedOrder($customer, '2026-06-01 10:00:00+00'); // Tehran 13:30

    $result = app(DrillService::class)->rows('orders', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows[0]['ordered_at'])->toMatch('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($result->rows[0]['ordered_at'])->not->toContain('2026-06-01');
});

it('lists customers whose first order fell in the period, for customers_new', function () {
    $inPeriod = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $inPeriod->id, 'first_order_at' => '2026-06-01 08:00:00+00']);
    $outside = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $outside->id, 'first_order_at' => '2026-05-20 08:00:00+00']);

    $result = app(DrillService::class)->rows('customers_new', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows)->toHaveCount(1)->and($result->rows[0]['customer_id'])->toBe($inPeriod->id);
});

it('formats the customers_new widget\'s first_order_at as Jalali/Tehran time (P6-11), never the raw Gregorian value', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'first_order_at' => '2026-06-01 08:00:00+00']);

    $result = app(DrillService::class)->rows('customers_new', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows[0]['first_order_at'])->toMatch('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($result->rows[0]['first_order_at'])->not->toContain('2026-06-01');
});

it('lists customers whose order in the period was not their first, for customers_repeat', function () {
    $repeat = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $repeat->id, 'first_order_at' => '2026-01-01 08:00:00+00']);
    realizedOrder($repeat, '2026-06-01 10:00:00');

    $newOnly = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $newOnly->id, 'first_order_at' => '2026-06-01 09:00:00+00']);
    realizedOrder($newOnly, '2026-06-01 09:00:00');

    $result = app(DrillService::class)->rows('customers_repeat', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows)->toHaveCount(1)->and($result->rows[0]['customer_id'])->toBe($repeat->id);
});

it('filters customers by rfm_segment, including the synthetic none bucket', function () {
    $champion = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $champion->id, 'rfm_segment' => 'champion']);
    $unscored = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $unscored->id, 'rfm_segment' => null]);

    $champions = app(DrillService::class)->rows('rfm_segment', drillPeriod('2026-06-01', '2026-06-02'), ['segment' => 'champion']);
    $none = app(DrillService::class)->rows('rfm_segment', drillPeriod('2026-06-01', '2026-06-02'), ['segment' => 'none']);

    expect($champions->rows)->toHaveCount(1)->and($champions->rows[0]['customer_id'])->toBe($champion->id)
        ->and($none->rows)->toHaveCount(1)->and($none->rows[0]['customer_id'])->toBe($unscored->id);
});

it('filters customers by churn_level, including the synthetic none bucket', function () {
    $atRisk = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $atRisk->id, 'churn_risk_level' => 'high']);
    $unscored = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $unscored->id, 'churn_risk_level' => null]);

    $high = app(DrillService::class)->rows('churn_level', drillPeriod('2026-06-01', '2026-06-02'), ['level' => 'high']);
    $none = app(DrillService::class)->rows('churn_level', drillPeriod('2026-06-01', '2026-06-02'), ['level' => 'none']);

    expect($high->rows)->toHaveCount(1)->and($high->rows[0]['customer_id'])->toBe($atRisk->id)
        ->and($none->rows)->toHaveCount(1)->and($none->rows[0]['customer_id'])->toBe($unscored->id);
});

it('returns null for an unknown widget rather than guessing', function () {
    expect(app(DrillService::class)->rows('not-a-real-widget', drillPeriod('2026-06-01', '2026-06-02'), []))->toBeNull();
});

it('truncates the JSON view and flags it, for a widget with more rows than the cap', function () {
    $customer = Customer::factory()->create();
    for ($i = 0; $i < DrillService::JSON_LIMIT + 5; $i++) {
        realizedOrder($customer, '2026-06-01 10:00:00');
    }

    $result = app(DrillService::class)->rows('orders', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows)->toHaveCount(DrillService::JSON_LIMIT)->and($result->truncated)->toBeTrue();
});

// ================================================================= cohort_period (P6-08)

it('lists customers active in a specific cohort/period cell, matching CohortSnapshotService\'s own definition', function () {
    $orderedAt = '2026-06-01 10:00:00';
    $cohortMonth = DB::selectOne('SELECT to_jalali_month(?::timestamptz) AS m', [$orderedAt])->m;

    $active = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $active->id, 'cohort_month' => $cohortMonth]);
    realizedOrder($active, $orderedAt, ['total' => 200_000]);

    $earlierPeriod = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $earlierPeriod->id, 'cohort_month' => $cohortMonth]);
    realizedOrder($earlierPeriod, '2020-01-01 10:00:00');

    $laterPeriod = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $laterPeriod->id, 'cohort_month' => $cohortMonth]);
    realizedOrder($laterPeriod, '2026-08-01 10:00:00');

    $result = app(DrillService::class)->rows('cohort_period', drillPeriod('2026-06-01', '2026-06-02'), [
        'cohort_month' => $cohortMonth, 'period_number' => '0',
    ]);

    expect($result->rows)->toHaveCount(1)->and($result->rows[0]['customer_id'])->toBe($active->id);
});

it('returns no rows for a cohort/period cell with no activity, not an error', function () {
    $result = app(DrillService::class)->rows('cohort_period', drillPeriod('2026-06-01', '2026-06-02'), [
        'cohort_month' => '1300-01', 'period_number' => '5',
    ]);

    expect($result->rows)->toHaveCount(0);
});

// ================================================================= affinity_pair (P6-08)

it('lists customers who bought both entities of a stored product-level affinity pair', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $both = Customer::factory()->create();
    DB::table('customer_product_purchases')->insert([
        ['customer_id' => $both->id, 'product_id' => $a->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1],
        ['customer_id' => $both->id, 'product_id' => $b->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1],
    ]);
    $onlyA = Customer::factory()->create();
    DB::table('customer_product_purchases')->insert(['customer_id' => $onlyA->id, 'product_id' => $a->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);

    $result = app(DrillService::class)->rows('affinity_pair', drillPeriod('2026-06-01', '2026-06-02'), [
        'affinity_level' => 'product', 'entity_a_id' => (string) $a->id, 'entity_b_id' => (string) $b->id,
    ]);

    expect($result->rows)->toHaveCount(1)->and($result->rows[0]['customer_id'])->toBe($both->id);
});

it('returns null for affinity_pair at the basket level, which is not implemented', function () {
    expect(app(DrillService::class)->rows('affinity_pair', drillPeriod('2026-06-01', '2026-06-02'), [
        'affinity_level' => 'basket', 'entity_a_id' => '1', 'entity_b_id' => '2',
    ]))->toBeNull();
});
