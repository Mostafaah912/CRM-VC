<?php

declare(strict_types=1);

use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

/*
| GATE 2 (PRD §26, hard gate before Sprint 5): the REAL pipeline — RecomputeMetricsJob, exactly as
| production dispatches it — must match tests/fixtures/expected_metrics.json exactly, not just the two
| independent oracles ExpectedMetricsFixtureTest.php already proves match the fixture (a PHP
| implementation and a raw-SQL query, both written before Sprint 4's real services existed). This is
| the first test to run the actual P4-01..07 pipeline against that fixture.
|
| Anchored at DemoDataSeeder::AS_OF via BaseAggregateService's injectable $asOf (P4-08): a literal
| NOW() can never reproduce a fixture frozen at a fixed historical instant, since real time keeps
| moving and recency_days (and everything downstream of it) would differ from the fixture every day
| this test runs. $asOf is a parameter only Gate 2 ever passes — production always uses real time.
*/

function expectedGate2(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/expected_metrics.json')), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(fn () => app(DemoDataSeeder::class)->run());

it('matches expected_metrics.json exactly after a real full recompute', function () {
    RecomputeMetricsJob::dispatchSync('full', DemoDataSeeder::asOf());

    $expected = expectedGate2();

    $rfmCounts = DB::table('customer_metrics')->whereNotNull('rfm_segment')
        ->selectRaw('rfm_segment, count(*) as n')->groupBy('rfm_segment')->pluck('n', 'rfm_segment');
    foreach ($expected['store']['rfm_segment_counts'] as $segment => $count) {
        expect((int) ($rfmCounts[$segment] ?? 0))->toBe($count, "rfm_segment {$segment}");
    }

    $lifecycleCounts = DB::table('customers')->where('lifecycle_stage', '!=', 'prospect')
        ->selectRaw('lifecycle_stage, count(*) as n')->groupBy('lifecycle_stage')->pluck('n', 'lifecycle_stage');
    foreach ($expected['store']['lifecycle_counts'] as $stage => $count) {
        expect((int) ($lifecycleCounts[$stage] ?? 0))->toBe($count, "lifecycle_stage {$stage}");
    }

    $churnCounts = DB::table('customer_metrics')->whereNotNull('churn_risk_level')
        ->selectRaw('churn_risk_level, count(*) as n')->groupBy('churn_risk_level')->pluck('n', 'churn_risk_level');
    foreach ($expected['store']['churn_level_counts'] as $level => $count) {
        expect((int) ($churnCounts[$level] ?? 0))->toBe($count, "churn_risk_level {$level}");
    }

    $rows = DB::table('customers')
        ->join('customer_metrics', 'customer_metrics.customer_id', '=', 'customers.id')
        ->where('customer_metrics.total_orders', '>', 0)
        ->select('customers.phone_normalized', 'customers.lifecycle_stage', 'customer_metrics.*')
        ->get()
        ->keyBy('phone_normalized');

    expect($rows)->toHaveCount(count($expected['customers']));

    foreach ($expected['customers'] as $phone => $exp) {
        $row = $rows[$phone] ?? null;
        expect($row)->not->toBeNull("missing customer {$phone}");

        expect((int) $row->total_orders)->toBe($exp['total_orders'], "{$phone} total_orders")
            ->and((int) $row->total_revenue)->toBe($exp['total_revenue'], "{$phone} total_revenue")
            ->and((int) $row->total_refunded)->toBe($exp['total_refunded'], "{$phone} total_refunded")
            ->and((int) $row->aov)->toBe($exp['aov'], "{$phone} aov")
            ->and((int) $row->monetary)->toBe($exp['monetary'], "{$phone} monetary")
            ->and((int) $row->frequency)->toBe($exp['frequency'], "{$phone} frequency")
            ->and((int) $row->recency_days)->toBe($exp['recency_days'], "{$phone} recency_days")
            ->and((int) $row->r_score)->toBe($exp['r_score'], "{$phone} r_score")
            ->and((int) $row->f_score)->toBe($exp['f_score'], "{$phone} f_score")
            ->and((int) $row->m_score)->toBe($exp['m_score'], "{$phone} m_score")
            ->and($row->rfm_score)->toBe($exp['rfm_score'], "{$phone} rfm_score")
            ->and($row->rfm_segment)->toBe($exp['rfm_segment'], "{$phone} rfm_segment")
            ->and((int) $row->clv_historical)->toBe($exp['clv_historical'], "{$phone} clv_historical")
            ->and($row->clv_estimated === null ? null : (int) $row->clv_estimated)->toBe($exp['clv_estimated'], "{$phone} clv_estimated")
            ->and($row->clv_confidence)->toBe($exp['clv_confidence'], "{$phone} clv_confidence")
            ->and((float) $row->churn_risk_score)->toEqual((float) $exp['churn_risk_score'], "{$phone} churn_risk_score")
            ->and($row->churn_risk_level)->toBe($exp['churn_risk_level'], "{$phone} churn_risk_level")
            ->and($row->lifecycle_stage)->toBe($exp['lifecycle_stage'], "{$phone} lifecycle_stage")
            ->and($row->cohort_month)->toBe($exp['cohort_month'], "{$phone} cohort_month")
            ->and((float) $row->purchase_cycle_days)->toEqual((float) $exp['purchase_cycle_days'], "{$phone} purchase_cycle_days")
            ->and($row->median_days_between === null ? null : (float) $row->median_days_between)
            ->toEqual($exp['median_days_between'] === null ? null : (float) $exp['median_days_between'], "{$phone} median_days_between")
            ->and($row->avg_days_between === null ? null : (float) $row->avg_days_between)
            ->toEqual($exp['avg_days_between'] === null ? null : (float) $exp['avg_days_between'], "{$phone} avg_days_between");
    }
});

it('completes a full recompute of the demo dataset in under 60 seconds', function () {
    $start = microtime(true);
    RecomputeMetricsJob::dispatchSync('full', DemoDataSeeder::asOf());
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(60.0, "Full recompute took {$elapsed}s, limit is 60s");
});
