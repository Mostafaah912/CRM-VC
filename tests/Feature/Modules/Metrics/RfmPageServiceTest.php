<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\RfmPageService;
use Illuminate\Support\Facades\DB;

/*
| P4-08 part B: RfmPageService::getData() reads only customer_metrics/metric_runs — both owned by
| Metrics, so nothing here needs another module's table or model. top_champions carries customer_id,
| total_revenue and rfm_score ONLY — never a name or phone (checked here at the data level; the arch
| test checks it at the source-code level).
*/

function rfmCustomer(array $metrics = []): Customer
{
    $customer = Customer::factory()->create();

    DB::table('customer_metrics')->insert(array_merge([
        'customer_id' => $customer->id,
        'total_orders' => 1,
    ], $metrics));

    return $customer;
}

it('counts every PRD segment plus none for a not-yet-eligible customer, never a missing key', function () {
    rfmCustomer(['rfm_segment' => 'champion']);
    rfmCustomer(['rfm_segment' => 'champion']);
    rfmCustomer(['rfm_segment' => 'loyal']);
    rfmCustomer(['total_orders' => 0, 'rfm_segment' => null]); // not eligible

    $segments = app(RfmPageService::class)->getData()['segments'];

    expect($segments)->toBe([
        'champion' => 2, 'loyal' => 1, 'promising' => 0, 'new_customer' => 0,
        'at_risk' => 0, 'cant_lose' => 0, 'hibernating' => 0, 'lost' => 0, 'none' => 1,
    ]);
});

it('gives a full 1..5 distribution for each of r/f/m, defaulting missing scores to 0', function () {
    rfmCustomer(['r_score' => 5, 'f_score' => 3, 'm_score' => 1]);
    rfmCustomer(['r_score' => 5, 'f_score' => 3, 'm_score' => 1]);
    rfmCustomer(['r_score' => 2, 'f_score' => 3, 'm_score' => 5]);

    $scores = app(RfmPageService::class)->getData()['scores'];

    expect($scores['r'])->toBe(['1' => 0, '2' => 1, '3' => 0, '4' => 0, '5' => 2])
        ->and($scores['f'])->toBe(['1' => 0, '2' => 0, '3' => 3, '4' => 0, '5' => 0])
        ->and($scores['m'])->toBe(['1' => 2, '2' => 0, '3' => 0, '4' => 0, '5' => 1]);
});

it('never counts a null score in the distribution', function () {
    rfmCustomer(['total_orders' => 0, 'r_score' => null, 'f_score' => null, 'm_score' => null]);

    $scores = app(RfmPageService::class)->getData()['scores'];

    expect(array_sum($scores['r']))->toBe(0)
        ->and(array_sum($scores['f']))->toBe(0)
        ->and(array_sum($scores['m']))->toBe(0);
});

it('gives the latest metric run\'s formatted finish time and status', function () {
    DB::table('metric_runs')->insert(['mode' => 'full', 'status' => 'failed', 'started_at' => now(), 'finished_at' => now()->subDay()]);
    DB::table('metric_runs')->insert(['mode' => 'full', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);

    $run = app(RfmPageService::class)->getData()['latest_run'];

    expect($run['status'])->toBe('completed')
        ->and($run['computed_at'])->not->toBeNull()
        ->and($run['computed_at_iso'])->not->toBeNull();
});

it('gives null latest_run when no metric run has ever been created', function () {
    expect(app(RfmPageService::class)->getData()['latest_run'])->toBeNull();
});

it('lists at most 10 champions by revenue, descending, with no name or phone', function () {
    foreach (range(1, 12) as $i) {
        rfmCustomer(['rfm_segment' => 'champion', 'total_revenue' => $i * 100_000, 'rfm_score' => '555']);
    }
    rfmCustomer(['rfm_segment' => 'loyal', 'total_revenue' => 999_999_999]); // never listed, wrong segment

    $champions = app(RfmPageService::class)->getData()['top_champions'];

    expect($champions)->toHaveCount(10)
        ->and($champions[0]['total_revenue'])->toBe(1_200_000)
        ->and($champions[9]['total_revenue'])->toBe(300_000)
        ->and(array_keys($champions[0]))->toBe(['customer_id', 'total_revenue', 'rfm_score']);
});

it('gives an empty champions list when nobody is a champion yet', function () {
    rfmCustomer(['rfm_segment' => 'loyal']);

    expect(app(RfmPageService::class)->getData()['top_champions'])->toBe([]);
});
