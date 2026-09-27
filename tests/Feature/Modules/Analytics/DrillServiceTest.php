<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\DrillService;
use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| P6-07 (TEST FIRST): PRD Sec.18's "هیچ عددی که پشتش دیده نشود قابل اعتماد نیست" — one uniform
| GET /internal/drill/{widget} behind it, DrillService::rows(). Scoped to the widgets that are a
| straightforward filtered row list (orders, new/repeat customers, RFM segment, churn level); cohort-cell
| and affinity-pair drill need real re-derivation and are documented as deferred, not guessed at.
| Rows never carry a name or phone (same PII-minimal rule as RfmPageService::topChampions()) -- only the
| audited CSV export (DrillExportServiceTest) may include identity columns.
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

it('lists customers whose first order fell in the period, for customers_new', function () {
    $inPeriod = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $inPeriod->id, 'first_order_at' => '2026-06-01 08:00:00+00']);
    $outside = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $outside->id, 'first_order_at' => '2026-05-20 08:00:00+00']);

    $result = app(DrillService::class)->rows('customers_new', drillPeriod('2026-06-01', '2026-06-02'), []);

    expect($result->rows)->toHaveCount(1)->and($result->rows[0]['customer_id'])->toBe($inPeriod->id);
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
