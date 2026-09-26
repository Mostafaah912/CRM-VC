<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\DailyMetricsService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| P6-02 (TEST FIRST): PRD §9's daily_metrics rollup. One INSERT...SELECT over a generate_series of
| calendar days (Asia/Tehran), upserted per day — never TRUNCATE (unlike P6-01's purchase aggregates):
| only the trailing window (BuildDailyMetricsJob(3)) is ever rebuilt, every other historical day must
| survive untouched. "new" vs "repeat" is read from customer_metrics.first_order_at (seeded directly
| here, same pattern BaseAggregateServiceTest uses, to test this service in isolation from the full
| Metrics pipeline).
*/

function dmMarkFirstOrder(Customer $customer, string $tehranDateTime): void
{
    DB::table('customer_metrics')->updateOrInsert(
        ['customer_id' => $customer->id],
        ['first_order_at' => CarbonImmutable::parse($tehranDateTime, 'Asia/Tehran')->utc()],
    );
}

function dmOrder(Customer $customer, string $tehranDateTime, array $overrides = []): Order
{
    return Order::factory()->for($customer)->create(array_merge([
        'is_realized' => true,
        'ordered_at' => CarbonImmutable::parse($tehranDateTime, 'Asia/Tehran')->utc(),
    ], $overrides));
}

function dmRow(string $date): ?object
{
    return DB::table('daily_metrics')->where('date', $date)->first();
}

it('excludes a non-realized order from the day it was placed', function () {
    $customer = Customer::factory()->create();
    dmOrder($customer, '2026-06-15 12:00:00', ['is_realized' => false, 'total' => 500_000]);

    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    $row = dmRow('2026-06-15');
    expect($row->orders_count)->toBe(0)->and($row->revenue)->toBe(0);
});

it('writes a zero row for a day with no orders at all — zero is a fact, not a missing row', function () {
    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    $row = dmRow('2026-06-15');
    expect($row)->not->toBeNull()
        ->and($row->orders_count)->toBe(0)
        ->and($row->customers_total)->toBe(0)
        ->and($row->aov)->toBe(0);
});

it('assigns an order by its Tehran-local calendar day, not its UTC day', function () {
    $customer = Customer::factory()->create();
    // 23:45 Tehran on the 15th is 20:15 UTC, still the 15th in UTC too here — pick a time that
    // actually crosses midnight in UTC to prove the Tehran offset (+03:30) is what's applied.
    dmOrder($customer, '2026-06-16 01:00:00', ['total' => 300_000]);

    app(DailyMetricsService::class)->rebuild(days: 2, asOf: CarbonImmutable::parse('2026-06-16 12:00:00', 'Asia/Tehran'));

    expect(dmRow('2026-06-16')->orders_count)->toBe(1)
        ->and(dmRow('2026-06-15')->orders_count)->toBe(0);
});

it('computes exact revenue/refunds/net_revenue/aov for a mixed day', function () {
    $a = Customer::factory()->create();
    $b = Customer::factory()->create();
    dmOrder($a, '2026-06-15 10:00:00', ['total' => 500_000, 'refunded_total' => 100_000]);
    dmOrder($b, '2026-06-15 11:00:00', ['total' => 300_000]);

    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    $row = dmRow('2026-06-15');
    expect($row->orders_count)->toBe(2)
        ->and($row->revenue)->toBe(800_000)
        ->and($row->refunds)->toBe(100_000)
        ->and($row->net_revenue)->toBe(700_000)
        ->and($row->aov)->toBe(350_000);
});

it('excludes a fully refunded order, matching Base Aggregates\' definition of a counted order', function () {
    $customer = Customer::factory()->create();
    dmOrder($customer, '2026-06-15 10:00:00', ['total' => 500_000, 'refunded_total' => 500_000, 'is_fully_refunded' => true]);

    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    expect(dmRow('2026-06-15')->orders_count)->toBe(0);
});

it('excludes a soft-deleted order', function () {
    $customer = Customer::factory()->create();
    $order = dmOrder($customer, '2026-06-15 10:00:00', ['total' => 500_000]);
    $order->delete();

    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    expect(dmRow('2026-06-15')->orders_count)->toBe(0);
});

it('splits new vs repeat customers and revenue using customer_metrics.first_order_at', function () {
    $newCustomer = Customer::factory()->create();
    dmMarkFirstOrder($newCustomer, '2026-06-15 10:00:00');
    dmOrder($newCustomer, '2026-06-15 10:00:00', ['total' => 200_000]);

    $repeatCustomer = Customer::factory()->create();
    dmMarkFirstOrder($repeatCustomer, '2026-05-01 09:00:00');
    dmOrder($repeatCustomer, '2026-06-15 11:00:00', ['total' => 300_000]);

    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    $row = dmRow('2026-06-15');
    expect($row->customers_total)->toBe(2)
        ->and($row->customers_new)->toBe(1)
        ->and($row->customers_repeat)->toBe(1)
        ->and($row->revenue_new)->toBe(200_000)
        ->and($row->revenue_repeat)->toBe(300_000);
});

it('counts a customer once even with two orders the same day, and correctly as new', function () {
    $customer = Customer::factory()->create();
    dmMarkFirstOrder($customer, '2026-06-15 08:00:00');
    dmOrder($customer, '2026-06-15 08:00:00', ['total' => 100_000]);
    dmOrder($customer, '2026-06-15 18:00:00', ['total' => 150_000]);

    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    $row = dmRow('2026-06-15');
    expect($row->orders_count)->toBe(2)
        ->and($row->customers_total)->toBe(1)
        ->and($row->customers_new)->toBe(1)
        ->and($row->revenue_new)->toBe(250_000);
});

it('is idempotent: rebuilding the same window twice does not duplicate or change rows', function () {
    $customer = Customer::factory()->create();
    dmOrder($customer, '2026-06-15 10:00:00', ['total' => 400_000]);

    $service = app(DailyMetricsService::class);
    $asOf = CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran');
    $service->rebuild(days: 1, asOf: $asOf);
    $first = dmRow('2026-06-15');
    $service->rebuild(days: 1, asOf: $asOf);
    $second = dmRow('2026-06-15');

    expect(DB::table('daily_metrics')->count())->toBe(1)
        ->and($second->revenue)->toBe($first->revenue)
        ->and($second->orders_count)->toBe($first->orders_count);
});

it('does not touch a historical day outside the rebuild window', function () {
    DB::table('daily_metrics')->insert([
        'date' => '2026-01-01', 'jalali_date' => '1404-10-11', 'orders_count' => 7,
        'revenue' => 111, 'refunds' => 0, 'net_revenue' => 111, 'aov' => 15,
        'customers_total' => 3, 'customers_new' => 1, 'customers_repeat' => 2,
        'revenue_new' => 50, 'revenue_repeat' => 61,
    ]);

    app(DailyMetricsService::class)->rebuild(days: 1, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    expect(dmRow('2026-01-01')->orders_count)->toBe(7);
});

it('rebuilds every day of a multi-day window, including days with zero orders in between', function () {
    $customer = Customer::factory()->create();
    dmOrder($customer, '2026-06-13 10:00:00', ['total' => 100_000]);
    dmOrder($customer, '2026-06-15 10:00:00', ['total' => 200_000]);

    $summary = app(DailyMetricsService::class)->rebuild(days: 3, asOf: CarbonImmutable::parse('2026-06-15 20:00:00', 'Asia/Tehran'));

    expect($summary->daysProcessed)->toBe(3)
        ->and(dmRow('2026-06-13')->revenue)->toBe(100_000)
        ->and(dmRow('2026-06-14')->orders_count)->toBe(0)
        ->and(dmRow('2026-06-15')->revenue)->toBe(200_000);
});
