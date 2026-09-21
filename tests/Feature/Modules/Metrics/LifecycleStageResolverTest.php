<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\LifecycleStageResolver;
use Illuminate\Support\Facades\DB;

/*
| P4-06 (TEST FIRST, PRD §11). lifecycle_stage lives on `customers` (CHECK'd there since P0-05),
| NOT on customer_metrics — customer_metrics has no such column at all, so this resolver reads
| total_orders/recency_days from customer_metrics but writes lifecycle_stage on customers, joined
| by customer_id. CASE order runs specific -> general (prospect first, lost last) exactly like the
| RFM segment mapping (P4-03): a customer must never fall through a later, broader branch before a
| more specific one gets to match.
*/

const LIFECYCLE_THRESHOLDS = ['p50' => 60, 'p75' => 120, 'p90' => 210];

function lifecycleCustomer(int $totalOrders, ?int $recencyDays): Customer
{
    $customer = Customer::factory()->create();

    DB::table('customer_metrics')->insert([
        'customer_id' => $customer->id,
        'total_orders' => $totalOrders,
        'recency_days' => $recencyDays,
    ]);

    return $customer;
}

function lifecycleStageFor(Customer $customer): ?string
{
    return DB::table('customers')->where('id', $customer->id)->value('lifecycle_stage');
}

it('gives prospect to a customer with zero orders', function () {
    $customer = lifecycleCustomer(0, null);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($customer))->toBe('prospect');
});

it('gives new to a single-order customer within p50', function () {
    $customer = lifecycleCustomer(1, 60);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($customer))->toBe('new');
});

it('gives active to a single-order customer between p50 and p75', function () {
    $justOver = lifecycleCustomer(1, 61);
    $atP75 = lifecycleCustomer(1, 120);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($justOver))->toBe('active')
        ->and(lifecycleStageFor($atP75))->toBe('active');
});

it('gives repeat to a 2-3 order customer within p75', function () {
    $two = lifecycleCustomer(2, 50);
    $three = lifecycleCustomer(3, 100);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($two))->toBe('repeat')
        ->and(lifecycleStageFor($three))->toBe('repeat');
});

it('gives loyal to a 4-or-more order customer within p75', function () {
    $four = lifecycleCustomer(4, 30);
    $ten = lifecycleCustomer(10, 90);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($four))->toBe('loyal')
        ->and(lifecycleStageFor($ten))->toBe('loyal');
});

it('gives at_risk to a customer between p75 and p90, regardless of order count', function () {
    $justOver = lifecycleCustomer(2, 121);
    $atP90 = lifecycleCustomer(5, 210);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($justOver))->toBe('at_risk')
        ->and(lifecycleStageFor($atP90))->toBe('at_risk');
});

it('gives dormant to a customer between p90 and p90*2', function () {
    $justOver = lifecycleCustomer(2, 211);
    $atP90Times2 = lifecycleCustomer(3, 420);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($justOver))->toBe('dormant')
        ->and(lifecycleStageFor($atP90Times2))->toBe('dormant');
});

it('gives lost to a customer beyond p90*2', function () {
    $justOver = lifecycleCustomer(1, 421);
    $wayOver = lifecycleCustomer(5, 500);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($justOver))->toBe('lost')
        ->and(lifecycleStageFor($wayOver))->toBe('lost');
});

// ================================================================== boundaries

it('puts exactly p75 in active, never at_risk', function () {
    $customer = lifecycleCustomer(1, 120);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($customer))->toBe('active');
});

it('puts exactly p90 in at_risk, never dormant', function () {
    $customer = lifecycleCustomer(2, 210);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($customer))->toBe('at_risk');
});

it('puts exactly p90*2 in dormant, never lost', function () {
    $customer = lifecycleCustomer(2, 420);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($customer))->toBe('dormant');
});

// ================================================================== guards

it('leaves lifecycle_stage untouched when total_orders >= 1 but recency_days is null — schema-safe guard', function () {
    // customers.lifecycle_stage is NOT NULL (CHECK'd since P0-05); this state should never happen
    // after P4-01 (recency_days is only null when total_orders=0), so the safest guard is to skip
    // the row entirely rather than attempt to write a NULL the column can never hold.
    $customer = lifecycleCustomer(1, null);
    DB::table('customers')->where('id', $customer->id)->update(['lifecycle_stage' => 'active']);

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($customer))->toBe('active');
});

it('never updates a soft-deleted customer\'s lifecycle_stage', function () {
    $customer = lifecycleCustomer(10, 30); // would resolve to 'loyal' if not excluded
    $customer->delete();

    app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS);

    expect(lifecycleStageFor($customer))->toBe('prospect'); // untouched schema default
});

it('returns the number of non-deleted customers updated', function () {
    lifecycleCustomer(0, null);
    lifecycleCustomer(1, 60);
    lifecycleCustomer(2, 50);
    lifecycleCustomer(4, 30);
    lifecycleCustomer(1, 421);
    $deleted = lifecycleCustomer(2, 30);
    $deleted->delete();

    expect(app(LifecycleStageResolver::class)->resolve(LIFECYCLE_THRESHOLDS))->toBe(5);
});
