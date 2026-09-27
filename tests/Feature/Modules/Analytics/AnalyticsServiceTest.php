<?php

declare(strict_types=1);

use App\Modules\Analytics\Enums\AffinityLevel;
use App\Modules\Analytics\Services\AffinityService;
use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| P6-06: AnalyticsService::dashboard() composes read-only queries over the tables P6-01..05 already
| built (daily_metrics, customer_metrics, cohort_snapshots, product_affinities) plus RetentionService's
| two lifetime metrics. No writes, no heavy per-order aggregation (PRD Sec.18: "no heavy aggregation at
| load time") -- everything here reads an already-materialized table.
*/

function dm(string $date, array $overrides = []): void
{
    DB::table('daily_metrics')->insert(array_merge([
        'date' => $date, 'jalali_date' => '1403-01-01',
        'orders_count' => 0, 'revenue' => 0, 'refunds' => 0, 'net_revenue' => 0, 'aov' => 0,
        'customers_total' => 0, 'customers_new' => 0, 'customers_repeat' => 0, 'revenue_new' => 0, 'revenue_repeat' => 0,
    ], $overrides));
}

function cm(array $overrides = []): int
{
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(array_merge(['customer_id' => $customer->id], $overrides));

    return $customer->id;
}

// ================================================================= period totals + compare

it('sums orders/revenue/aov over the requested period only, not the whole table', function () {
    dm('2026-06-01', ['orders_count' => 2, 'revenue' => 200_000, 'net_revenue' => 180_000, 'aov' => 90_000]);
    dm('2026-06-02', ['orders_count' => 3, 'revenue' => 300_000, 'net_revenue' => 270_000, 'aov' => 90_000]);
    dm('2026-05-31', ['orders_count' => 99, 'revenue' => 9_990_000, 'net_revenue' => 9_990_000, 'aov' => 100_000]); // outside the period

    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));
    $data = app(AnalyticsService::class)->dashboard($period);

    expect($data['current']['orders_count'])->toBe(5)
        ->and($data['current']['net_revenue'])->toBe(450_000)
        ->and($data['current']['aov'])->toBe(90_000);
});

it('includes the Jalali equivalent of the period boundaries, for the drill-down links', function () {
    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));

    $data = app(AnalyticsService::class)->dashboard($period);

    expect($data['period']['from_jalali'])->toBe(JalaliDate::format(CarbonImmutable::parse('2026-06-01'), '/'))
        ->and($data['period']['to_jalali'])->toBe(JalaliDate::format(CarbonImmutable::parse('2026-06-02'), '/'));
});

it('computes the previous period of equal length immediately before the current one, for compare', function () {
    dm('2026-06-03', ['orders_count' => 10, 'net_revenue' => 1_000_000]);
    dm('2026-06-04', ['orders_count' => 10, 'net_revenue' => 1_000_000]);
    dm('2026-06-01', ['orders_count' => 4, 'net_revenue' => 400_000]); // the 2-day period right before
    dm('2026-06-02', ['orders_count' => 4, 'net_revenue' => 400_000]);
    dm('2026-05-31', ['orders_count' => 999, 'net_revenue' => 99_000_000]); // one day further back: must not be included

    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-03'), CarbonImmutable::parse('2026-06-04'));
    $data = app(AnalyticsService::class)->dashboard($period);

    expect($data['current']['orders_count'])->toBe(20)
        ->and($data['previous']['orders_count'])->toBe(8)
        ->and($data['previous']['net_revenue'])->toBe(800_000);
});

it('treats a missing day as contributing zero, never an error, when the range has no rows yet', function () {
    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-03'));

    $data = app(AnalyticsService::class)->dashboard($period);

    expect($data['current']['orders_count'])->toBe(0)->and($data['current']['net_revenue'])->toBe(0)
        ->and($data['current']['aov'])->toBe(0);
});

it('returns the daily trend rows within the period, ordered by date, and none outside it', function () {
    dm('2026-06-01', ['orders_count' => 1, 'jalali_date' => '1405-03-11']);
    dm('2026-06-02', ['orders_count' => 2, 'jalali_date' => '1405-03-12']);
    dm('2026-05-31', ['orders_count' => 9]);

    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));
    $data = app(AnalyticsService::class)->dashboard($period);

    expect($data['trend'])->toHaveCount(2)
        ->and($data['trend'][0]['date'])->toBe('2026-06-01')
        ->and($data['trend'][1]['date'])->toBe('2026-06-02');
});

// ================================================================= lifetime metrics (unfiltered by period)

it('includes the lifetime repeat purchase rate and returning revenue share, unaffected by the period', function () {
    $period = DashboardPeriod::fromDates(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));

    $data = app(AnalyticsService::class)->dashboard($period);

    expect($data['repeat_purchase_rate']['insufficient_data'])->toBeTrue()
        ->and($data['returning_revenue_share']['insufficient_data'])->toBeTrue();
});

// ================================================================= RFM / churn / value at risk

it('gives every RFM segment a key, defaulting to zero, plus none for an unscored customer', function () {
    cm(['rfm_segment' => 'champion']);
    cm(['rfm_segment' => 'champion']);
    cm(); // unscored

    $data = app(AnalyticsService::class)->dashboard(DashboardPeriod::lastDays(30, CarbonImmutable::now()));

    expect($data['rfm_distribution']['champion'])->toBe(2)
        ->and($data['rfm_distribution']['none'])->toBe(1)
        ->and($data['rfm_distribution']['lost'])->toBe(0);
});

it('gives every churn risk level a key and sums CLV only for high/lost as value at risk', function () {
    cm(['churn_risk_level' => 'high', 'clv_estimated' => 500_000, 'clv_historical' => 100_000]);
    cm(['churn_risk_level' => 'lost', 'clv_estimated' => null, 'clv_historical' => 200_000]);
    cm(['churn_risk_level' => 'low', 'clv_estimated' => 900_000, 'clv_historical' => 900_000]);
    cm(); // unscored

    $data = app(AnalyticsService::class)->dashboard(DashboardPeriod::lastDays(30, CarbonImmutable::now()));

    expect($data['churn_distribution']['high'])->toBe(1)
        ->and($data['churn_distribution']['lost'])->toBe(1)
        ->and($data['churn_distribution']['low'])->toBe(1)
        ->and($data['churn_distribution']['medium'])->toBe(0)
        ->and($data['churn_distribution']['none'])->toBe(1)
        ->and($data['value_at_risk'])->toBe(700_000); // 500_000 (estimated) + 200_000 (fallback: historical, estimated is null)
});

// ================================================================= cohort matrix + top affinity passthrough

it('includes the cohort matrix and top affinity from their own services', function () {
    DB::table('cohort_snapshots')->insert([
        'cohort_month' => '1403-01', 'period_number' => 0, 'cohort_size' => 5, 'active_customers' => 5,
        'retention_rate' => 1.0, 'orders_count' => 5, 'revenue' => 500_000, 'cumulative_revenue' => 500_000,
    ]);
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    for ($i = 0; $i < 10; $i++) {
        $customerId = Customer::factory()->create()->id;
        DB::table('customer_product_purchases')->insert(['customer_id' => $customerId, 'product_id' => $a->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
        DB::table('customer_product_purchases')->insert(['customer_id' => $customerId, 'product_id' => $b->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
    }
    for ($i = 0; $i < 10; $i++) {
        DB::table('customer_product_purchases')->insert(['customer_id' => Customer::factory()->create()->id, 'product_id' => Product::factory()->create()->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
    }
    app(AffinityService::class)->rebuild();

    $data = app(AnalyticsService::class)->dashboard(DashboardPeriod::lastDays(30, CarbonImmutable::now()));

    expect($data['cohort_matrix'])->toHaveCount(1)
        ->and($data['cohort_matrix'][0]['cohort_month'])->toBe('1403-01')
        ->and($data['top_affinity'])->toHaveCount(1)
        ->and($data['top_affinity'][0]['level'])->toBe(AffinityLevel::Product->value);
});
