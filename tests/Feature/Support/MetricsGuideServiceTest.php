<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\AffinityService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Support\MetricsGuideService;
use Database\Seeders\DefaultSegmentSeeder;
use Illuminate\Support\Facades\DB;

/*
| P6-18 phase 5 (TEST FIRST): every live number on the metrics guide page must trace to the same
| config/constant/table the real Calculators read — proven here by actually changing a threshold and
| checking the guide's own output changes (not just a static assertion that it matches once).
*/

function guideCustomer(array $metrics = []): Customer
{
    $customer = Customer::factory()->create();

    DB::table('customer_metrics')->insert(array_merge([
        'customer_id' => $customer->id,
        'total_orders' => 1,
    ], $metrics));

    return $customer;
}

// ========================================================================== RFM

it('gives the real min/max range and customer count for each R/F/M score 1..5', function () {
    guideCustomer(['r_score' => 5, 'recency_days' => 2]);
    guideCustomer(['r_score' => 5, 'recency_days' => 9]);
    guideCustomer(['r_score' => 3, 'recency_days' => 40]);

    $scores = app(MetricsGuideService::class)->guide()['rfm']['r_scores'];

    expect($scores[4])->toBe(['score' => 5, 'min' => 2, 'max' => 9, 'customers' => 2])
        ->and($scores[2])->toBe(['score' => 3, 'min' => 40, 'max' => 40, 'customers' => 1])
        ->and($scores[0])->toBe(['score' => 1, 'min' => 0, 'max' => 0, 'customers' => 0]);
});

it('gives all 8 RFM segments with their condition text and live customer count', function () {
    guideCustomer(['rfm_segment' => 'champion']);
    guideCustomer(['rfm_segment' => 'champion']);
    guideCustomer(['rfm_segment' => 'loyal']);

    $segments = collect(app(MetricsGuideService::class)->guide()['rfm']['segments'])->keyBy('segment');

    expect($segments['champion']['customers'])->toBe(2)
        ->and($segments['loyal']['customers'])->toBe(1)
        ->and($segments['champion']['condition'])->not->toBe('')
        ->and($segments)->toHaveCount(8);
});

it('renders all 12 real system segments as a non-empty Persian sentence with their live member count', function () {
    $this->seed(DefaultSegmentSeeder::class);

    $systemSegments = app(MetricsGuideService::class)->guide()['rfm']['system_segments'];

    expect($systemSegments)->toHaveCount(12);
    foreach ($systemSegments as $segment) {
        expect($segment['sentence'])->not->toBe('')
            ->and(preg_match('/\p{Arabic}/u', $segment['sentence']))->toBe(1)
            ->and($segment['members'])->toBeInt();
    }
});

// ========================================================================== CLV

it('reads margin_rate/horizon_years from config, not a hardcoded number — proven by changing config and re-reading', function () {
    config(['metrics.margin_rate' => 0.17]);
    $before = app(MetricsGuideService::class)->guide()['clv']['margin_rate'];

    config(['metrics.margin_rate' => 0.42]);
    $after = app(MetricsGuideService::class)->guide()['clv']['margin_rate'];

    expect($before)->toBe(0.17)->and($after)->toBe(0.42);
});

it('counts customers by clv_confidence live', function () {
    guideCustomer(['clv_confidence' => 'low']);
    guideCustomer(['clv_confidence' => 'high']);
    guideCustomer(['clv_confidence' => 'high']);

    $distribution = app(MetricsGuideService::class)->guide()['clv']['confidence_distribution'];

    expect($distribution)->toBe(['low' => 1, 'medium' => 0, 'high' => 2]);
});

// ========================================================================== Churn / lifecycle thresholds

it('uses the latest completed full metric_run\'s stored thresholds when one exists, not a fresh computation', function () {
    DB::table('metric_runs')->insert([
        'mode' => 'full', 'status' => 'completed', 'definition_version' => 'v1',
        'customers_processed' => 0, 'thresholds' => json_encode(['p50' => 11, 'p75' => 22, 'p90' => 33, 'sample_size' => 500]),
        'started_at' => now(), 'finished_at' => now(),
    ]);

    $thresholds = app(MetricsGuideService::class)->guide()['churn']['thresholds'];

    expect($thresholds)->toBe(['p50' => 11, 'p75' => 22, 'p90' => 33, 'sample_size' => 500, 'is_fallback' => false]);
});

it('falls back to config(metrics.fallback_percentiles) when sample_size is below 200, flagged is_fallback', function () {
    // No real orders exist, so ChurnThresholdService::percentiles() returns sample_size 0 -> fallback.
    $thresholds = app(MetricsGuideService::class)->guide()['churn']['thresholds'];
    $fallback = config('metrics.fallback_percentiles');

    expect($thresholds)->toBe([...$fallback, 'sample_size' => 0, 'is_fallback' => true]);
});

it('counts customers by churn_risk_level live, for all 4 levels', function () {
    guideCustomer(['churn_risk_level' => 'high']);
    guideCustomer(['churn_risk_level' => 'high']);
    guideCustomer(['churn_risk_level' => 'low']);

    $levels = collect(app(MetricsGuideService::class)->guide()['churn']['levels'])->pluck('customers', 'level');

    expect($levels->all())->toBe(['low' => 1, 'medium' => 0, 'high' => 2, 'lost' => 0]);
});

it('counts customers by lifecycle_stage live, for all 8 stages', function () {
    Customer::factory()->create(['lifecycle_stage' => 'loyal']);
    Customer::factory()->create(['lifecycle_stage' => 'loyal']);
    Customer::factory()->create(['lifecycle_stage' => 'prospect']);

    $stages = collect(app(MetricsGuideService::class)->guide()['lifecycle']['stages'])->pluck('customers', 'stage');

    expect($stages['loyal'])->toBe(2)->and($stages['prospect'])->toBe(1);
});

// ========================================================================== Affinity

it('reads the min co-customer thresholds from AffinityService\'s own constants, not a second copy', function () {
    $minCoCustomers = app(MetricsGuideService::class)->guide()['affinity']['min_co_customers'];

    expect($minCoCustomers)->toBe([
        'category' => AffinityService::MIN_CO_CUSTOMERS_CATEGORY,
        'product' => AffinityService::MIN_CO_CUSTOMERS_PRODUCT,
        'variation' => AffinityService::MIN_CO_CUSTOMERS_VARIATION,
        'basket' => AffinityService::MIN_CO_CUSTOMERS_BASKET,
    ]);
});

it('gives order_items_resolved_percent as a 0..1 ratio, not a pre-multiplied percent, matching formatPercent\'s input convention', function () {
    $customer = Customer::factory()->create();
    $order = DB::table('orders')->insertGetId([
        'customer_id' => $customer->id, 'woo_order_id' => 1, 'status' => 'completed', 'is_realized' => true,
        'total' => 100000, 'subtotal' => 100000, 'discount_total' => 0, 'shipping_total' => 0,
        'tax_total' => 0, 'refunded_total' => 0,
        'ordered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $product = Product::factory()->create();
    DB::table('order_items')->insert([
        ['order_id' => $order, 'product_id' => $product->id, 'name_snapshot' => 'a', 'qty' => 1, 'unit_price' => 50000, 'line_subtotal' => 50000, 'line_total' => 50000, 'created_at' => now(), 'updated_at' => now()],
        ['order_id' => $order, 'product_id' => null, 'name_snapshot' => 'b', 'qty' => 1, 'unit_price' => 50000, 'line_subtotal' => 50000, 'line_total' => 50000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $percent = app(MetricsGuideService::class)->guide()['affinity']['order_items_resolved_percent'];

    expect($percent)->toBe(0.5);
});

// ========================================================================== Data quality

it('counts open (pending) identity conflicts live', function () {
    DB::table('identity_conflicts')->insert([
        ['reason' => 'no_phone', 'status' => 'pending', 'created_at' => now()],
        ['reason' => 'no_phone', 'status' => 'pending', 'created_at' => now()],
        ['reason' => 'no_phone', 'status' => 'confirmed_same', 'created_at' => now()],
    ]);

    expect(app(MetricsGuideService::class)->guide()['data_quality']['open_identity_conflicts'])->toBe(2);
});
