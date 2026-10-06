<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\RfmCalculator;
use Illuminate\Support\Facades\DB;

/*
| P6-20 (TEST FIRST, product-owner decision): RFM's "M" can score from a 60-day window instead of
| lifetime `monetary`, behind `config('metrics.monetary.mode')`. R and F are untouched by this — only
| M's source column and scoring method change. `config('metrics.monetary.mode')` defaults to
| 'lifetime' (RfmCalculatorTest.php, GATE 2) so every test here sets 'recent_window' explicitly.
|
| Rows are built directly against customer_metrics (same convention as RfmCalculatorTest.php) so each
| test controls monetary_recent independently of monetary, real PostgreSQL only (percentile_cont).
*/

function monetaryWindowCustomer(array $metrics = []): Customer
{
    $customer = Customer::factory()->create($metrics['customer'] ?? []);
    unset($metrics['customer']);

    DB::table('customer_metrics')->insert(array_merge([
        'customer_id' => $customer->id,
        'total_orders' => 1,
        'recency_days' => 30,
        'frequency' => 5,
        'monetary' => 0,
    ], $metrics));

    return $customer;
}

/** A customer with a realized order in the 60-day window, for a given monetary_recent amount. */
function windowPurchaser(int $amount): Customer
{
    return monetaryWindowCustomer(['monetary_recent' => $amount]);
}

function monetaryScoresFor(Customer $customer): object
{
    return DB::table('customer_metrics')->where('customer_id', $customer->id)->first();
}

// ================================================================== exact values on a small fixture

it('scores m_score from log-median/MAD cut-points on a 9-customer geometric fixture, not NTILE ranks', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    // Amounts double each step (1M..256M): log-spaced evenly, so median/MAD are exact and the 4
    // z = -0.84/-0.25/+0.25/+0.84 cut-points land cleanly between amounts, nowhere near one — no
    // customer here sits within 3x of a cut-point, so this fixture is robust to float rounding.
    $customers = collect([1, 2, 4, 8, 16, 32, 64, 128, 256])
        ->mapWithKeys(fn (int $multiplier) => [$multiplier => windowPurchaser($multiplier * 1_000_000)]);

    app(RfmCalculator::class)->compute();

    expect(monetaryScoresFor($customers[1])->m_score)->toBe(1)
        ->and(monetaryScoresFor($customers[2])->m_score)->toBe(1)
        ->and(monetaryScoresFor($customers[4])->m_score)->toBe(2)
        ->and(monetaryScoresFor($customers[8])->m_score)->toBe(2)
        ->and(monetaryScoresFor($customers[16])->m_score)->toBe(3)
        ->and(monetaryScoresFor($customers[32])->m_score)->toBe(4)
        ->and(monetaryScoresFor($customers[64])->m_score)->toBe(4)
        ->and(monetaryScoresFor($customers[128])->m_score)->toBe(5)
        ->and(monetaryScoresFor($customers[256])->m_score)->toBe(5);
});

// ================================================================== ties

it('gives customers with equal monetary_recent the same m_score, never a tie-split', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    $tied = collect(range(1, 3))->map(fn () => windowPurchaser(5_000_000));
    windowPurchaser(1_000_000);
    windowPurchaser(50_000_000);

    app(RfmCalculator::class)->compute();

    $scores = $tied->map(fn (Customer $c) => monetaryScoresFor($c)->m_score);
    expect($scores->unique())->toHaveCount(1);
});

// ================================================================== outlier resistance

it('keeps a meaningful score spread among modest spenders when one extreme outlier is present', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    $modest = collect([1_000_000, 2_000_000, 4_000_000, 8_000_000, 16_000_000])
        ->map(fn (int $amount) => windowPurchaser($amount));
    $outlier = windowPurchaser(50_000_000_000);

    app(RfmCalculator::class)->compute();

    $modestScores = $modest->map(fn (Customer $c) => monetaryScoresFor($c)->m_score);
    expect($modestScores->unique()->count())->toBeGreaterThan(1)
        ->and($modestScores->first())->toBeLessThan($modestScores->last())
        ->and(monetaryScoresFor($outlier)->m_score)->toBe(5);
});

// ================================================================== no purchase in window -> 1

it('gives m_score 1 to a customer with orders but none in the window, regardless of their lifetime monetary', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    $noWindowPurchase = monetaryWindowCustomer(['monetary' => 999_000_000, 'monetary_recent' => null]);
    windowPurchaser(2_000_000);
    windowPurchaser(5_000_000);
    windowPurchaser(10_000_000);

    app(RfmCalculator::class)->compute();

    expect(monetaryScoresFor($noWindowPurchase)->m_score)->toBe(1);
});

it('gives m_score 1 to every eligible customer when nobody purchased in the window at all', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    monetaryWindowCustomer(['monetary' => 500_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['monetary' => 300_000, 'monetary_recent' => null]);

    app(RfmCalculator::class)->compute();

    expect(DB::table('customer_metrics')->pluck('m_score')->unique()->all())->toBe([1]);
});

// ================================================================== degenerate: zero spread

it('gives every window purchaser the same middle score when there is no spread at all (MAD = 0)', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    collect(range(1, 4))->each(fn () => windowPurchaser(5_000_000));

    app(RfmCalculator::class)->compute();

    expect(DB::table('customer_metrics')->whereNotNull('monetary_recent')->pluck('m_score')->unique()->all())->toBe([3]);
});

// ================================================================== R and F untouched

it('leaves r_score and f_score exactly as NTILE would, unaffected by the monetary mode', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    // NTILE(5) needs >= 5 rows to actually span 1..5 (with fewer rows it just numbers them
    // sequentially from 1) — same 5-customer shape RfmCalculatorTest.php's own R/F tests use.
    $freshest = monetaryWindowCustomer(['recency_days' => 1, 'frequency' => 5, 'monetary_recent' => 1_000_000]);
    monetaryWindowCustomer(['recency_days' => 10, 'frequency' => 4, 'monetary_recent' => 1_000_000]);
    monetaryWindowCustomer(['recency_days' => 20, 'frequency' => 3, 'monetary_recent' => 1_000_000]);
    monetaryWindowCustomer(['recency_days' => 30, 'frequency' => 2, 'monetary_recent' => 1_000_000]);
    monetaryWindowCustomer(['recency_days' => 40, 'frequency' => 1, 'monetary_recent' => 1_000_000]);

    app(RfmCalculator::class)->compute();

    expect(monetaryScoresFor($freshest)->r_score)->toBe(5)
        ->and(monetaryScoresFor($freshest)->f_score)->toBe(5);
});

// ================================================================== rfm_score string stays consistent

it('rebuilds rfm_score from the recent_window m_score, not a stale lifetime one', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    $target = monetaryWindowCustomer(['recency_days' => 1, 'frequency' => 6, 'monetary' => 1, 'monetary_recent' => 100_000_000]);
    windowPurchaser(1_000_000);
    windowPurchaser(2_000_000);
    windowPurchaser(4_000_000);

    app(RfmCalculator::class)->compute();

    $row = monetaryScoresFor($target);
    expect($row->rfm_score)->toBe("{$row->r_score}{$row->f_score}{$row->m_score}");
});

// ================================================================== idempotent

it('gives the same m_score on a repeated recent_window computation', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    $customers = collect([1_000_000, 5_000_000, 20_000_000, 80_000_000])->map(fn (int $a) => windowPurchaser($a));

    app(RfmCalculator::class)->compute();
    $first = $customers->map(fn (Customer $c) => monetaryScoresFor($c)->m_score)->all();

    app(RfmCalculator::class)->compute();
    $second = $customers->map(fn (Customer $c) => monetaryScoresFor($c)->m_score)->all();

    expect($second)->toBe($first);
});

// ================================================================== mode switching (GATE 2 safety)

it('keeps m_score lifetime-NTILE-based when mode is lifetime, ignoring monetary_recent entirely', function () {
    config(['metrics.monetary.mode' => 'lifetime']);

    $lowest = monetaryWindowCustomer(['monetary' => 100, 'monetary_recent' => 999_000_000]);
    monetaryWindowCustomer(['monetary' => 200, 'monetary_recent' => null]);
    monetaryWindowCustomer(['monetary' => 300, 'monetary_recent' => 1]);
    monetaryWindowCustomer(['monetary' => 400, 'monetary_recent' => null]);
    $highest = monetaryWindowCustomer(['monetary' => 500, 'monetary_recent' => null]);

    app(RfmCalculator::class)->compute();

    expect(monetaryScoresFor($lowest)->m_score)->toBe(1)
        ->and(monetaryScoresFor($highest)->m_score)->toBe(5);
});

// ================================================================== cut-points exposed for persistence

it('exposes the computed cut-points, strictly increasing, for the orchestrator to persist', function () {
    config(['metrics.monetary.mode' => 'recent_window', 'metrics.monetary.window_days' => 60]);

    collect([1_000_000, 5_000_000, 20_000_000, 80_000_000])->each(fn (int $a) => windowPurchaser($a));

    $calculator = app(RfmCalculator::class);
    $calculator->compute();
    $cutpoints = $calculator->monetaryCutpoints();

    expect($cutpoints)->not->toBeNull()
        ->and($cutpoints['window_days'])->toBe(60)
        ->and($cutpoints['c1'])->toBeLessThan($cutpoints['c2'])
        ->and($cutpoints['c2'])->toBeLessThan($cutpoints['c3'])
        ->and($cutpoints['c3'])->toBeLessThan($cutpoints['c4']);
});

it('exposes null cut-points when mode is lifetime', function () {
    config(['metrics.monetary.mode' => 'lifetime']);

    $calculator = app(RfmCalculator::class);
    $calculator->compute();

    expect($calculator->monetaryCutpoints())->toBeNull();
});

// ================================================================== P6-21: cant_lose uses lifetime M

it('classifies cant_lose from the lifetime monetary NTILE, not the windowed m_score, in recent_window mode', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    // Target: oldest of the group (r_score=1), frequency=5 among [6,5,3,2,1] -> f_score=4 (the exact
    // shape RfmCalculatorTest.php's own cant_lose test already validates E3 never touches), highest
    // lifetime `monetary` in the group (lifetime NTILE -> top bucket) but NO purchase in the 60-day
    // window at all (monetary_recent null for everyone -> the degenerate "nobody purchased" case
    // forces windowed m_score=1 for all five, which would wrongly fail cant_lose's m>=4 requirement
    // without the fix).
    $target = monetaryWindowCustomer(['recency_days' => 100, 'frequency' => 5, 'monetary' => 900_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 40, 'frequency' => 6, 'monetary' => 700_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 30, 'frequency' => 3, 'monetary' => 500_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 20, 'frequency' => 2, 'monetary' => 300_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 10, 'frequency' => 1, 'monetary' => 100_000, 'monetary_recent' => null]);

    app(RfmCalculator::class)->compute();

    $row = monetaryScoresFor($target);
    expect($row->r_score)->toBe(1)
        ->and($row->f_score)->toBe(4)
        ->and($row->m_score)->toBe(1) // the DISPLAYED/windowed M stays 1 (no recent purchase) — only the segment rule reads lifetime M
        ->and($row->rfm_segment)->toBe('cant_lose');
});

it('still classifies lost, not cant_lose, when lifetime monetary NTILE is below 4 — even with r=1 and f>=4', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    $target = monetaryWindowCustomer(['recency_days' => 100, 'frequency' => 5, 'monetary' => 100_000, 'monetary_recent' => null]); // lowest lifetime M in the group
    monetaryWindowCustomer(['recency_days' => 40, 'frequency' => 6, 'monetary' => 300_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 30, 'frequency' => 3, 'monetary' => 500_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 20, 'frequency' => 2, 'monetary' => 700_000, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 10, 'frequency' => 1, 'monetary' => 900_000, 'monetary_recent' => null]);

    app(RfmCalculator::class)->compute();

    $row = monetaryScoresFor($target);
    expect($row->f_score)->toBe(4)
        ->and($row->rfm_segment)->toBe('lost');
});

it('gives the identical cant_lose/lost segment under both monetary modes for the same fixture — the lifetime-M fix makes it mode-independent', function () {
    $build = function (): Customer {
        $target = monetaryWindowCustomer(['recency_days' => 100, 'frequency' => 5, 'monetary' => 900_000, 'monetary_recent' => null]);
        monetaryWindowCustomer(['recency_days' => 40, 'frequency' => 6, 'monetary' => 700_000, 'monetary_recent' => null]);
        monetaryWindowCustomer(['recency_days' => 30, 'frequency' => 3, 'monetary' => 500_000, 'monetary_recent' => null]);
        monetaryWindowCustomer(['recency_days' => 20, 'frequency' => 2, 'monetary' => 300_000, 'monetary_recent' => null]);
        monetaryWindowCustomer(['recency_days' => 10, 'frequency' => 1, 'monetary' => 100_000, 'monetary_recent' => null]);

        return $target;
    };

    config(['metrics.monetary.mode' => 'lifetime']);
    $targetLifetime = $build();
    app(RfmCalculator::class)->compute();
    $segmentLifetime = monetaryScoresFor($targetLifetime)->rfm_segment;

    DB::table('customer_metrics')->delete();
    DB::table('customers')->delete();

    config(['metrics.monetary.mode' => 'recent_window']);
    $targetWindow = $build();
    app(RfmCalculator::class)->compute();
    $segmentWindow = monetaryScoresFor($targetWindow)->rfm_segment;

    expect($segmentLifetime)->toBe('cant_lose')->and($segmentWindow)->toBe('cant_lose');
});

it('leaves ineligible customers with a null segment under the recent_window lifetime-M cant_lose CTE too', function () {
    config(['metrics.monetary.mode' => 'recent_window']);

    $ineligible = monetaryWindowCustomer(['total_orders' => 0, 'recency_days' => null, 'frequency' => 0, 'monetary' => 0, 'monetary_recent' => null]);
    monetaryWindowCustomer(['recency_days' => 30, 'frequency' => 5, 'monetary' => 500_000, 'monetary_recent' => null]);

    app(RfmCalculator::class)->compute();

    expect(monetaryScoresFor($ineligible)->rfm_segment)->toBeNull();
});
