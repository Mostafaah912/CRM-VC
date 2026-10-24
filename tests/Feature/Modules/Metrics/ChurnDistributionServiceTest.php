<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Services\ChurnDistributionService;
use Illuminate\Support\Facades\DB;

/*
| P6-06's dashboard "churn risk distribution + value at risk" widget needs a Metrics-owned read of
| customer_metrics/ChurnRiskLevel — same reasoning RfmPageService already established for RFM (Analytics
| may only reach another module through its public Service, never its Enums, so this lives in Metrics).
*/

function cds(array $overrides = []): int
{
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(array_merge(['customer_id' => $customer->id], $overrides));

    return $customer->id;
}

it('gives every churn risk level a key, defaulting to zero, plus none for an unscored customer', function () {
    cds(['churn_risk_level' => 'high']);
    cds(['churn_risk_level' => 'high']);
    cds(); // unscored

    $summary = app(ChurnDistributionService::class)->summary();

    expect($summary['distribution']['high'])->toBe(2)
        ->and($summary['distribution']['low'])->toBe(0)
        ->and($summary['distribution']['medium'])->toBe(0)
        ->and($summary['distribution']['lost'])->toBe(0)
        ->and($summary['distribution']['none'])->toBe(1);
});

it('reports value at risk as two figures — lifetime revenue of high-risk and of lost customers — never from the CLV projection', function () {
    // clv_estimated is absurdly large on purpose: it must have no say (a minutes-long purchase cycle used to blow it up).
    cds(['churn_risk_level' => 'high', 'total_revenue' => 500_000, 'clv_estimated' => 9_000_000_000, 'clv_historical' => 85_000]);
    cds(['churn_risk_level' => 'high', 'total_revenue' => 250_000, 'clv_estimated' => null, 'clv_historical' => 42_500]);
    cds(['churn_risk_level' => 'lost', 'total_revenue' => 200_000, 'clv_estimated' => 7_000_000_000, 'clv_historical' => 34_000]);
    cds(['churn_risk_level' => 'low', 'total_revenue' => 900_000, 'clv_estimated' => 900_000, 'clv_historical' => 900_000]);
    cds(['churn_risk_level' => 'medium', 'total_revenue' => 900_000, 'clv_estimated' => 900_000, 'clv_historical' => 900_000]);

    $summary = app(ChurnDistributionService::class)->summary();

    expect($summary['value_at_risk'])->toBe(['high' => 750_000, 'lost' => 200_000]);
});

it('reports zero for both figures when no customer is high or lost risk, not an error', function () {
    cds(['churn_risk_level' => 'low', 'total_revenue' => 900_000, 'clv_estimated' => 900_000]);

    $summary = app(ChurnDistributionService::class)->summary();

    expect($summary['value_at_risk'])->toBe(['high' => 0, 'lost' => 0]);
});

it('reports zero for a level that has no customers while the other has some', function () {
    cds(['churn_risk_level' => 'lost', 'total_revenue' => 123_000]);

    expect(app(ChurnDistributionService::class)->summary()['value_at_risk'])->toBe(['high' => 0, 'lost' => 123_000]);
});
