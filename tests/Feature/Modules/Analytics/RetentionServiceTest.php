<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\RetentionService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| P6-04 (TEST FIRST): PRD §15's store-level retention metrics. No new table and no Job: PRD §22's job
| list has no dedicated job for these, and each is a cheap read over already-materialized
| `customer_metrics`/`orders` — the same "no heavy aggregation at page load" rule PRD §18 states for the
| Dashboard is satisfied by reading rollups, not by adding yet another cache table. Pure read services:
| "idempotency" here means calling twice never mutates anything and returns the same numbers.
*/

function rsSeedMetrics(Customer $customer, ?CarbonImmutable $firstOrderAt, int $totalOrders): void
{
    // DB::table()->insert() has no Eloquent date-cast to normalize a Carbon instance to UTC before
    // formatting it (unlike Order::create(), whose 'ordered_at' => 'datetime' cast does) — pass an
    // explicit UTC instant here, or a Tehran-timezone Carbon silently gets written 3.5 hours off
    // (CLAUDE.md §2 / ARCHITECTURE.md P0-05's exact connection-timezone pitfall).
    DB::table('customer_metrics')->updateOrInsert(
        ['customer_id' => $customer->id],
        ['first_order_at' => $firstOrderAt?->utc(), 'total_orders' => $totalOrders],
    );
}

function rsOrder(Customer $customer, CarbonImmutable $orderedAt, array $overrides = []): Order
{
    // Eloquent's 'datetime' cast formats the Carbon instance's wall-clock digits as-is; it does not
    // itself convert timezone before writing (same pitfall as rsSeedMetrics above) — pass UTC explicitly,
    // same as DailyMetricsServiceTest's dmOrder() helper (P6-02) already established.
    return Order::factory()->for($customer)->create(array_merge([
        'is_realized' => true,
        'ordered_at' => $orderedAt->utc(),
    ], $overrides));
}

// ============================================================== Repeat Purchase Rate

it('computes exact repeat purchase rate from customer_metrics.total_orders', function () {
    $a = Customer::factory()->create();
    $b = Customer::factory()->create();
    $c = Customer::factory()->create();
    $prospect = Customer::factory()->create();
    rsSeedMetrics($a, CarbonImmutable::now(), 3); // repeat
    rsSeedMetrics($b, CarbonImmutable::now(), 1); // one-time
    rsSeedMetrics($c, CarbonImmutable::now(), 2); // repeat
    rsSeedMetrics($prospect, null, 0); // never ordered, excluded from the denominator

    $result = app(RetentionService::class)->repeatPurchaseRate();

    expect($result->eligibleCustomers)->toBe(3)
        ->and($result->repeatCustomers)->toBe(2)
        ->and($result->rate)->toEqualWithDelta(2 / 3, 0.0001)
        ->and($result->insufficientData)->toBeFalse();
});

it('flags insufficient data for repeat purchase rate when no customer has ever ordered', function () {
    Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => Customer::factory()->create()->id, 'total_orders' => 0]);

    $result = app(RetentionService::class)->repeatPurchaseRate();

    expect($result->eligibleCustomers)->toBe(0)
        ->and($result->rate)->toBeNull()
        ->and($result->insufficientData)->toBeTrue();
});

it('excludes a soft-deleted customer from repeat purchase rate', function () {
    $customer = Customer::factory()->create();
    rsSeedMetrics($customer, CarbonImmutable::now(), 5);
    $customer->delete();

    $result = app(RetentionService::class)->repeatPurchaseRate();

    expect($result->eligibleCustomers)->toBe(0);
});

// ============================================================== Returning revenue share

it('computes exact returning revenue share: only orders after the customer\'s first_order_at count as returning', function () {
    $customer = Customer::factory()->create();
    $firstOrderAt = CarbonImmutable::parse('2026-01-10 10:00:00');
    rsSeedMetrics($customer, $firstOrderAt, 2);
    rsOrder($customer, $firstOrderAt, ['total' => 100_000]); // the acquisition order itself: NOT returning
    rsOrder($customer, $firstOrderAt->addDays(10), ['total' => 300_000]); // returning

    $result = app(RetentionService::class)->returningRevenueShare();

    expect($result->totalRevenue)->toBe(400_000)
        ->and($result->returningRevenue)->toBe(300_000)
        ->and($result->share)->toEqualWithDelta(0.75, 0.0001);
});

it('excludes a non-realized order from returning revenue share', function () {
    $customer = Customer::factory()->create();
    $firstOrderAt = CarbonImmutable::parse('2026-01-10 10:00:00');
    rsSeedMetrics($customer, $firstOrderAt, 1);
    rsOrder($customer, $firstOrderAt, ['total' => 100_000]);
    rsOrder($customer, $firstOrderAt->addDays(5), ['total' => 999_000, 'is_realized' => false]);

    $result = app(RetentionService::class)->returningRevenueShare();

    expect($result->totalRevenue)->toBe(100_000)
        ->and($result->returningRevenue)->toBe(0);
});

it('nets a fully refunded order to zero in returning revenue share via net_revenue, no explicit filter needed', function () {
    $customer = Customer::factory()->create();
    $firstOrderAt = CarbonImmutable::parse('2026-01-10 10:00:00');
    rsSeedMetrics($customer, $firstOrderAt, 2);
    rsOrder($customer, $firstOrderAt, ['total' => 100_000]);
    rsOrder($customer, $firstOrderAt->addDays(5), ['total' => 500_000, 'refunded_total' => 500_000, 'is_fully_refunded' => true]);

    $result = app(RetentionService::class)->returningRevenueShare();

    expect($result->totalRevenue)->toBe(100_000)
        ->and($result->returningRevenue)->toBe(0);
});

it('flags insufficient data for returning revenue share when there is no realized revenue at all', function () {
    $result = app(RetentionService::class)->returningRevenueShare();

    expect($result->totalRevenue)->toBe(0)
        ->and($result->share)->toBeNull()
        ->and($result->insufficientData)->toBeTrue();
});

// ============================================================== N-day retention

it('computes exact N-day retention over mature customers only', function () {
    $asOf = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');

    // Mature (first order >= 30 days before asOf) and returned within the 30-day window.
    $returned = Customer::factory()->create();
    $firstA = $asOf->subDays(40);
    rsSeedMetrics($returned, $firstA, 2);
    rsOrder($returned, $firstA);
    rsOrder($returned, $firstA->addDays(10));

    // Mature but never returned within the window.
    $notReturned = Customer::factory()->create();
    $firstB = $asOf->subDays(35);
    rsSeedMetrics($notReturned, $firstB, 1);
    rsOrder($notReturned, $firstB);

    $result = app(RetentionService::class)->nDayRetention(30, $asOf);

    expect($result->matureCustomers)->toBe(2)
        ->and($result->returnedCustomers)->toBe(1)
        ->and($result->retentionRate)->toEqualWithDelta(0.5, 0.0001)
        ->and($result->insufficientData)->toBeFalse();
});

it('excludes an immature customer entirely, even if they already returned early — the immature-cohort trap', function () {
    $asOf = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');

    // First order only 2 days before asOf: the 30-day window has not had a chance to fully elapse yet,
    // even though this customer already placed a second order. Including them would inflate the rate
    // with a biased, incomplete sample — exactly PRD's "immature cohort trap".
    $tooYoung = Customer::factory()->create();
    $firstOrderAt = $asOf->subDays(2);
    rsSeedMetrics($tooYoung, $firstOrderAt, 2);
    rsOrder($tooYoung, $firstOrderAt);
    rsOrder($tooYoung, $firstOrderAt->addDay());

    $result = app(RetentionService::class)->nDayRetention(30, $asOf);

    expect($result->matureCustomers)->toBe(0)
        ->and($result->returnedCustomers)->toBe(0)
        ->and($result->insufficientData)->toBeTrue();
});

it('includes a customer exactly at the maturity boundary (first_order_at = asOf - N days), the boundary is inclusive', function () {
    $asOf = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');
    $customer = Customer::factory()->create();
    $firstOrderAt = $asOf->subDays(30);
    rsSeedMetrics($customer, $firstOrderAt, 1);
    rsOrder($customer, $firstOrderAt);

    $result = app(RetentionService::class)->nDayRetention(30, $asOf);

    expect($result->matureCustomers)->toBe(1);
});

it('gives the same result whether asOf is expressed in Asia/Tehran or UTC — both are the same instant', function () {
    $asOfTehran = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');
    $customer = Customer::factory()->create();
    $firstOrderAt = $asOfTehran->subDays(40);
    rsSeedMetrics($customer, $firstOrderAt, 1);
    rsOrder($customer, $firstOrderAt);

    $viaTehran = app(RetentionService::class)->nDayRetention(30, $asOfTehran);
    $viaUtc = app(RetentionService::class)->nDayRetention(30, $asOfTehran->utc());

    expect($viaTehran->matureCustomers)->toBe($viaUtc->matureCustomers)
        ->and($viaTehran->matureCustomers)->toBe(1);
});

it('excludes a non-realized second order from counting as a return', function () {
    $asOf = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');
    $customer = Customer::factory()->create();
    $firstOrderAt = $asOf->subDays(40);
    rsSeedMetrics($customer, $firstOrderAt, 1);
    rsOrder($customer, $firstOrderAt);
    rsOrder($customer, $firstOrderAt->addDays(5), ['is_realized' => false]);

    $result = app(RetentionService::class)->nDayRetention(30, $asOf);

    expect($result->matureCustomers)->toBe(1)
        ->and($result->returnedCustomers)->toBe(0);
});

it('excludes a fully refunded second order from counting as a return', function () {
    $asOf = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');
    $customer = Customer::factory()->create();
    $firstOrderAt = $asOf->subDays(40);
    rsSeedMetrics($customer, $firstOrderAt, 1);
    rsOrder($customer, $firstOrderAt);
    rsOrder($customer, $firstOrderAt->addDays(5), ['total' => 500_000, 'refunded_total' => 500_000, 'is_fully_refunded' => true]);

    $result = app(RetentionService::class)->nDayRetention(30, $asOf);

    expect($result->matureCustomers)->toBe(1)
        ->and($result->returnedCustomers)->toBe(0);
});

it('excludes a return outside the N-day window', function () {
    $asOf = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');
    $customer = Customer::factory()->create();
    $firstOrderAt = $asOf->subDays(40);
    rsSeedMetrics($customer, $firstOrderAt, 1);
    rsOrder($customer, $firstOrderAt);
    rsOrder($customer, $firstOrderAt->addDays(31)); // one day past the 30-day window

    $result = app(RetentionService::class)->nDayRetention(30, $asOf);

    expect($result->matureCustomers)->toBe(1)
        ->and($result->returnedCustomers)->toBe(0);
});

it('is idempotent: calling twice returns identical results and writes nothing', function () {
    $asOf = CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran');
    $customer = Customer::factory()->create();
    $firstOrderAt = $asOf->subDays(40);
    rsSeedMetrics($customer, $firstOrderAt, 2);
    rsOrder($customer, $firstOrderAt);
    rsOrder($customer, $firstOrderAt->addDays(10));

    $service = app(RetentionService::class);
    $first = $service->nDayRetention(30, $asOf);
    $second = $service->nDayRetention(30, $asOf);

    expect($second->matureCustomers)->toBe($first->matureCustomers)
        ->and($second->returnedCustomers)->toBe($first->returnedCustomers)
        ->and($second->retentionRate)->toBe($first->retentionRate);
});
