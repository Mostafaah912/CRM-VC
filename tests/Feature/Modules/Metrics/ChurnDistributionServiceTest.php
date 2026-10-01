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

it('sums estimated CLV (falling back to historical) only for high/lost as value at risk', function () {
    cds(['churn_risk_level' => 'high', 'clv_estimated' => 500_000, 'clv_historical' => 100_000]);
    cds(['churn_risk_level' => 'lost', 'clv_estimated' => null, 'clv_historical' => 200_000]);
    cds(['churn_risk_level' => 'low', 'clv_estimated' => 900_000, 'clv_historical' => 900_000]);
    cds(['churn_risk_level' => 'medium', 'clv_estimated' => 900_000, 'clv_historical' => 900_000]);

    $summary = app(ChurnDistributionService::class)->summary();

    expect($summary['value_at_risk'])->toBe(700_000);
});

it('reports zero value at risk when no customer is high or lost risk, not an error', function () {
    cds(['churn_risk_level' => 'low', 'clv_estimated' => 900_000]);

    $summary = app(ChurnDistributionService::class)->summary();

    expect($summary['value_at_risk'])->toBe(0);
});
