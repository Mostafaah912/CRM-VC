<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\RfmCalculator;
use Illuminate\Support\Facades\DB;

/*
| P4-03 (TEST FIRST, PRD §12). Three classic RFM bugs, each locked down before RfmCalculator exists:
|   E1 — frequency NTILE must be ASC (more orders = higher score)
|   E2 — recency NTILE must be ORDER BY recency_days DESC (fewer days since = higher score)
|   E3 — a customer with frequency <= 4 can never score above their own order count
| Eligible: customer_metrics.total_orders >= 1 AND customers.deleted_at IS NULL AND customers.status
| = 'active'. Everyone else gets NULL scores and a NULL rfm_segment — never 'lost'.
| Rows are built directly against customer_metrics (not via BaseAggregateService/DemoDataSeeder) so
| each test controls recency/frequency/monetary independently, real PostgreSQL only (NTILE).
*/

function customerWithMetrics(array $metrics = []): Customer
{
    $customer = Customer::factory()->create($metrics['customer'] ?? []);
    unset($metrics['customer']);

    DB::table('customer_metrics')->insert(array_merge([
        'customer_id' => $customer->id,
        'total_orders' => 1,
    ], $metrics));

    return $customer;
}

function scoresFor(Customer $customer): object
{
    return DB::table('customer_metrics')->where('customer_id', $customer->id)->first();
}

// ================================================================== E2 — recency direction

it('gives the highest r_score to the most recently ordered customer, the lowest to the oldest', function () {
    $freshest = customerWithMetrics(['recency_days' => 1, 'frequency' => 5, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 10, 'frequency' => 5, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 20, 'frequency' => 5, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 5, 'monetary' => 0]);
    $oldest = customerWithMetrics(['recency_days' => 40, 'frequency' => 5, 'monetary' => 0]);

    app(RfmCalculator::class)->compute();

    expect(scoresFor($freshest)->r_score)->toBe(5)
        ->and(scoresFor($oldest)->r_score)->toBe(1);
});

// ================================================================== E1 — frequency direction

it('gives the highest f_score to the most frequent customer, the lowest to a single-order one', function () {
    $mostFrequent = customerWithMetrics(['recency_days' => 30, 'frequency' => 5, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 4, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 3, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 2, 'monetary' => 0]);
    $singleBuyer = customerWithMetrics(['recency_days' => 30, 'frequency' => 1, 'monetary' => 0]);

    app(RfmCalculator::class)->compute();

    expect(scoresFor($mostFrequent)->f_score)->toBe(5)
        ->and(scoresFor($singleBuyer)->f_score)->toBe(1);
});

// ================================================================== E3 — frequency correction

it('never lets a single-order customer score above f_score 1, however NTILE ranked them', function () {
    // Four frequency=1 customers spread NTILE across buckets 1-4 by customer_id; a frequency=5 fills bucket 5.
    $singleBuyers = collect(range(1, 4))->map(fn () => customerWithMetrics(['recency_days' => 30, 'frequency' => 1, 'monetary' => 0]));
    customerWithMetrics(['recency_days' => 30, 'frequency' => 5, 'monetary' => 0]);

    app(RfmCalculator::class)->compute();

    $singleBuyers->each(fn (Customer $c) => expect(scoresFor($c)->f_score)->toBe(1));
});

it('caps f_score at the customer\'s own frequency for frequency <= 4, leaves frequency >= 5 unrestricted', function () {
    $freq1 = customerWithMetrics(['recency_days' => 30, 'frequency' => 1, 'monetary' => 0]); // raw bucket 1
    $freq2 = customerWithMetrics(['recency_days' => 30, 'frequency' => 2, 'monetary' => 0]); // raw bucket 2
    $freq3 = customerWithMetrics(['recency_days' => 30, 'frequency' => 3, 'monetary' => 0]); // raw bucket 3
    $freq4 = customerWithMetrics(['recency_days' => 30, 'frequency' => 4, 'monetary' => 0]); // raw bucket 4
    $freq5 = customerWithMetrics(['recency_days' => 30, 'frequency' => 5, 'monetary' => 0]); // raw bucket 5

    app(RfmCalculator::class)->compute();

    expect(scoresFor($freq1)->f_score)->toBe(1)
        ->and(scoresFor($freq2)->f_score)->toBe(2)
        ->and(scoresFor($freq3)->f_score)->toBe(3)
        ->and(scoresFor($freq4)->f_score)->toBe(4)
        ->and(scoresFor($freq5)->f_score)->toBe(5);
});

it('rebuilds rfm_score from the corrected f_score, not the raw NTILE bucket', function () {
    // Target: r_score=5 (freshest), raw f bucket=3 but frequency=2 (corrects to 2), m_score=4.
    $target = customerWithMetrics(['recency_days' => 1, 'frequency' => 2, 'monetary' => 400]);
    customerWithMetrics(['recency_days' => 10, 'frequency' => 1, 'monetary' => 100]);
    customerWithMetrics(['recency_days' => 20, 'frequency' => 1, 'monetary' => 200]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 3, 'monetary' => 300]);
    customerWithMetrics(['recency_days' => 40, 'frequency' => 4, 'monetary' => 500]);

    app(RfmCalculator::class)->compute();

    $row = scoresFor($target);
    expect([$row->r_score, $row->f_score, $row->m_score])->toBe([5, 2, 4])
        ->and($row->rfm_score)->toBe('524');
});

// ================================================================== non-eligible -> NULL

it('leaves a customer with zero orders with null scores and a null segment', function () {
    $customer = customerWithMetrics([
        'total_orders' => 0, 'recency_days' => null, 'frequency' => 0, 'monetary' => 0,
        // stale values from a prior run, to prove nullify actively clears them
        'r_score' => 3, 'f_score' => 3, 'm_score' => 3, 'rfm_score' => '333', 'rfm_segment' => 'loyal',
    ]);

    app(RfmCalculator::class)->compute();

    $row = scoresFor($customer);
    expect([$row->r_score, $row->f_score, $row->m_score, $row->rfm_score, $row->rfm_segment])
        ->toBe([null, null, null, null, null]);
});

it('leaves a soft-deleted customer with null scores', function () {
    $customer = customerWithMetrics(['recency_days' => 5, 'frequency' => 3, 'monetary' => 100]);
    $customer->delete();

    app(RfmCalculator::class)->compute();

    $row = scoresFor($customer);
    expect([$row->r_score, $row->f_score, $row->m_score, $row->rfm_segment])->toBe([null, null, null, null]);
});

it('leaves a non-active customer with null scores', function () {
    $customer = customerWithMetrics(['customer' => ['status' => 'blocked'], 'recency_days' => 5, 'frequency' => 3, 'monetary' => 100]);

    app(RfmCalculator::class)->compute();

    $row = scoresFor($customer);
    expect([$row->r_score, $row->f_score, $row->m_score, $row->rfm_segment])->toBe([null, null, null, null]);
});

// ================================================================== determinism

it('gives the same scores on every run when customers tie on recency_days', function () {
    $a = customerWithMetrics(['recency_days' => 15, 'frequency' => 2, 'monetary' => 100]);
    $b = customerWithMetrics(['recency_days' => 15, 'frequency' => 2, 'monetary' => 100]);

    app(RfmCalculator::class)->compute();
    $first = [scoresFor($a)->r_score, scoresFor($b)->r_score];

    app(RfmCalculator::class)->compute();
    $second = [scoresFor($a)->r_score, scoresFor($b)->r_score];

    expect($second)->toBe($first);
});

// ================================================================== segment mapping order

it('assigns cant_lose, not lost, to a churned-but-high-value repeat customer', function () {
    // Target: r_score=1 (oldest), f_score=4 and m_score=4 (frequency=5 so E3 never touches f_score).
    $target = customerWithMetrics(['recency_days' => 100, 'frequency' => 5, 'monetary' => 400]);
    customerWithMetrics(['recency_days' => 40, 'frequency' => 6, 'monetary' => 500]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 3, 'monetary' => 300]);
    customerWithMetrics(['recency_days' => 20, 'frequency' => 2, 'monetary' => 200]);
    customerWithMetrics(['recency_days' => 10, 'frequency' => 1, 'monetary' => 100]);

    app(RfmCalculator::class)->compute();

    $row = scoresFor($target);
    expect([$row->r_score, $row->f_score, $row->m_score])->toBe([1, 4, 4])
        ->and($row->rfm_segment)->toBe('cant_lose');
});

it('assigns champion to a customer scoring r_score >= 4 and f_score >= 4', function () {
    // Target: r_score=4 (2nd freshest), f_score=4 (frequency=5, E3 does not touch it).
    $target = customerWithMetrics(['recency_days' => 10, 'frequency' => 5, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 1, 'frequency' => 1, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 20, 'frequency' => 2, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 3, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 40, 'frequency' => 6, 'monetary' => 0]);

    app(RfmCalculator::class)->compute();

    $row = scoresFor($target);
    expect([$row->r_score, $row->f_score])->toBe([4, 4])
        ->and($row->rfm_segment)->toBe('champion');
});

it('assigns new_customer to a freshest single-order customer, never champion', function () {
    // Target: r_score=5 (freshest), frequency=1 -> f_score forced to 1 by E3 -> fails champion/loyal.
    $target = customerWithMetrics(['recency_days' => 1, 'frequency' => 1, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 10, 'frequency' => 2, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 20, 'frequency' => 3, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 4, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 40, 'frequency' => 5, 'monetary' => 0]);

    app(RfmCalculator::class)->compute();

    $row = scoresFor($target);
    expect([$row->r_score, $row->f_score, $row->rfm_segment])->toBe([5, 1, 'new_customer']);
});

it('gives a non-eligible customer a null rfm_segment, never a fabricated one', function () {
    $customer = customerWithMetrics(['total_orders' => 0, 'recency_days' => null, 'frequency' => 0, 'monetary' => 0]);

    app(RfmCalculator::class)->compute();

    expect(scoresFor($customer)->rfm_segment)->toBeNull();
});

// ================================================================== compute() return value

it('returns the number of eligible customers scored', function () {
    customerWithMetrics(['recency_days' => 10, 'frequency' => 1, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 20, 'frequency' => 2, 'monetary' => 0]);
    customerWithMetrics(['recency_days' => 30, 'frequency' => 3, 'monetary' => 0]);
    customerWithMetrics(['total_orders' => 0, 'recency_days' => null, 'frequency' => 0, 'monetary' => 0]);
    customerWithMetrics(['customer' => ['status' => 'blocked'], 'recency_days' => 5, 'frequency' => 1, 'monetary' => 0]);
    $deleted = customerWithMetrics(['recency_days' => 5, 'frequency' => 1, 'monetary' => 0]);
    $deleted->delete();

    expect(app(RfmCalculator::class)->compute())->toBe(3);
});
