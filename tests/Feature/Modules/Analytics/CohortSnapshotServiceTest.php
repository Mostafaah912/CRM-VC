<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\CohortSnapshotService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| P6-03 (TEST FIRST): PRD §15's cohort_snapshots — one TRUNCATE + INSERT...SELECT over a full
| (cohort_month x period_number) grid, so an immature period gets a real row (is_mature=false), not a
| missing one — matches the "immature cohort trap" warning: a matrix cell must be greyed out, never
| silently rendered as a 0. cohort_month/first_order_at are seeded directly into customer_metrics (same
| pattern BaseAggregateServiceTest/DailyMetricsServiceTest use) to test this service in isolation from
| the full Metrics pipeline. Test dates go through JalaliDate::toGregorian(), per CLAUDE.md §2.
*/

function csSeedCohort(Customer $customer, string $cohortMonth): void
{
    DB::table('customer_metrics')->updateOrInsert(
        ['customer_id' => $customer->id],
        ['cohort_month' => $cohortMonth],
    );
}

/** A realized order at noon Tehran time on the given Jalali year/month/day (default the 10th). */
function csOrder(Customer $customer, int $jy, int $jm, array $overrides = [], int $jd = 10, string $tehranTime = '12:00'): Order
{
    [$hour, $minute] = array_map('intval', explode(':', $tehranTime));
    $orderedAt = JalaliDate::toGregorian($jy, $jm, $jd)->setTimezone('Asia/Tehran')->setTime($hour, $minute)->utc();

    return Order::factory()->for($customer)->create(array_merge([
        'is_realized' => true,
        'ordered_at' => $orderedAt,
    ], $overrides));
}

function csRow(string $cohortMonth, int $period): ?object
{
    return DB::table('cohort_snapshots')->where('cohort_month', $cohortMonth)->where('period_number', $period)->first();
}

function csAsOf(int $jy, int $jm): CarbonImmutable
{
    return JalaliDate::toGregorian($jy, $jm, 15)->setTimezone('Asia/Tehran')->setTime(12, 0);
}

it('computes exact cohort_size, active_customers, retention_rate, orders_count, revenue and cumulative_revenue', function () {
    $a = Customer::factory()->create();
    $b = Customer::factory()->create();
    csSeedCohort($a, '1404-01');
    csSeedCohort($b, '1404-01');
    csOrder($a, 1404, 1, ['total' => 100_000]); // period 0
    csOrder($b, 1404, 1, ['total' => 200_000]); // period 0
    csOrder($a, 1404, 2, ['total' => 50_000]); // period 1

    app(CohortSnapshotService::class)->rebuild(csAsOf(1404, 6));

    $period0 = csRow('1404-01', 0);
    expect($period0->cohort_size)->toBe(2)
        ->and($period0->active_customers)->toBe(2)
        ->and((float) $period0->retention_rate)->toBe(1.0)
        ->and($period0->orders_count)->toBe(2)
        ->and($period0->revenue)->toBe(300_000)
        ->and($period0->cumulative_revenue)->toBe(300_000);

    $period1 = csRow('1404-01', 1);
    expect($period1->active_customers)->toBe(1)
        ->and((float) $period1->retention_rate)->toBe(0.5)
        ->and($period1->orders_count)->toBe(1)
        ->and($period1->revenue)->toBe(50_000)
        ->and($period1->cumulative_revenue)->toBe(350_000);
});

it('excludes a non-realized order from activity: it neither counts an active customer nor its revenue', function () {
    $a = Customer::factory()->create();
    csSeedCohort($a, '1404-01');
    csOrder($a, 1404, 1, ['total' => 100_000]); // period 0, realized
    csOrder($a, 1404, 2, ['total' => 999_000, 'is_realized' => false]); // period 1, NOT realized

    app(CohortSnapshotService::class)->rebuild(csAsOf(1404, 6));

    $period1 = csRow('1404-01', 1);
    expect($period1->active_customers)->toBe(0)
        ->and($period1->orders_count)->toBe(0)
        ->and($period1->revenue)->toBe(0)
        ->and($period1->cumulative_revenue)->toBe(100_000);
});

it('excludes a fully refunded and a soft-deleted order, matching Base Aggregates\' counted-order definition', function () {
    $a = Customer::factory()->create();
    csSeedCohort($a, '1404-01');
    csOrder($a, 1404, 1, ['total' => 100_000]);
    csOrder($a, 1404, 2, ['total' => 500_000, 'refunded_total' => 500_000, 'is_fully_refunded' => true]);
    $softDeleted = csOrder($a, 1404, 3, ['total' => 700_000]);
    $softDeleted->delete();

    app(CohortSnapshotService::class)->rebuild(csAsOf(1404, 6));

    expect(csRow('1404-01', 1)->orders_count)->toBe(0)
        ->and(csRow('1404-01', 2)->orders_count)->toBe(0);
});

it('assigns an order to the correct Jalali month at the Tehran-local month boundary, not the UTC one', function () {
    $a = Customer::factory()->create();
    csSeedCohort($a, '1404-01');
    // 00:15 Tehran on the 1st of month 2 is 20:45 UTC on the PREVIOUS Gregorian day. A bug that read the
    // Jalali month from the raw UTC instant instead of calling to_jalali_month() (which itself applies
    // Asia/Tehran) would misattribute this order to month 1's last day (period 0) instead of month 2.
    csOrder($a, 1404, 2, ['total' => 400_000], jd: 1, tehranTime: '00:15');

    app(CohortSnapshotService::class)->rebuild(csAsOf(1404, 6));

    expect(csRow('1404-01', 1)->orders_count)->toBe(1)
        ->and(csRow('1404-01', 0)->orders_count)->toBe(0);
});

it('flags a period that has not started yet as immature, per the literal PRD formula (>=, not >)', function () {
    $a = Customer::factory()->create();
    csSeedCohort($a, '1404-01');
    csOrder($a, 1404, 1, ['total' => 100_000]);

    // asOf = 1404-02: jalali_month_diff('1404-01','1404-02') = 1.
    app(CohortSnapshotService::class)->rebuild(csAsOf(1404, 2));

    expect(csRow('1404-01', 0)->is_mature)->toBeTrue() // 1 >= 0
        ->and(csRow('1404-01', 1)->is_mature)->toBeTrue() // 1 >= 1, the boundary/current month
        ->and(csRow('1404-01', 2)->is_mature)->toBeFalse() // 1 >= 2 is false: hasn't happened yet
        ->and(csRow('1404-01', 2)->active_customers)->toBe(0);
});

it('is idempotent: rebuilding twice does not duplicate or change rows', function () {
    $a = Customer::factory()->create();
    csSeedCohort($a, '1404-01');
    csOrder($a, 1404, 1, ['total' => 250_000]);

    $service = app(CohortSnapshotService::class);
    $asOf = csAsOf(1404, 6);
    $service->rebuild($asOf);
    $countAfterFirst = DB::table('cohort_snapshots')->count();
    $first = csRow('1404-01', 0);
    $service->rebuild($asOf);
    $countAfterSecond = DB::table('cohort_snapshots')->count();
    $second = csRow('1404-01', 0);

    expect($countAfterSecond)->toBe($countAfterFirst)
        ->and($second->revenue)->toBe($first->revenue)
        ->and($second->active_customers)->toBe($first->active_customers);
});

it('rebuilds from scratch: a stale cohort_month no longer present in customer_metrics is gone', function () {
    DB::table('cohort_snapshots')->insert([
        'cohort_month' => '1399-01', 'period_number' => 0, 'cohort_size' => 5, 'active_customers' => 5,
        'retention_rate' => 1, 'orders_count' => 5, 'revenue' => 1, 'cumulative_revenue' => 1,
    ]);

    app(CohortSnapshotService::class)->rebuild(csAsOf(1404, 6));

    expect(DB::table('cohort_snapshots')->where('cohort_month', '1399-01')->exists())->toBeFalse();
});
