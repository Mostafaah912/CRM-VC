<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\SchemaProbe;

function metricsCustomer(): int
{
    return DB::table('customers')->insertGetId([
        'phone_normalized' => '989'.fake()->unique()->numerify('#########'), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function metricsRow(array $overrides = []): int
{
    $customerId = $overrides['customer_id'] ?? metricsCustomer();
    DB::table('customer_metrics')->insert(array_merge(['customer_id' => $customerId], $overrides));

    return $customerId;
}

it('has metric_runs exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('metric_runs', [
        'id' => ['bigint', false, null],
        'mode' => ['varchar(10)', false, null],
        'definition_version' => ['varchar(10)', false, "'v1'"],
        'status' => ['varchar(15)', false, "'running'"],
        'customers_processed' => ['int', false, '0'],
        'thresholds' => ['jsonb', true, null],
        'started_at' => ['tstz', false, 'current_timestamp'],
        'finished_at' => ['tstz', true, null],
        'error' => ['text', true, null],
    ]))->toBeEmpty();
});

it('has customer_metrics exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('customer_metrics', [
        'customer_id' => ['bigint', false, null],
        'first_order_at' => ['tstz', true, null],
        'last_order_at' => ['tstz', true, null],
        'total_orders' => ['int', false, '0'],
        'total_revenue' => ['bigint', false, '0'],
        'total_refunded' => ['bigint', false, '0'],
        'aov' => ['bigint', false, '0'],
        'recency_days' => ['int', true, null],
        'frequency' => ['int', false, '0'],
        'monetary' => ['bigint', false, '0'],
        'r_score' => ['smallint', true, null],
        'f_score' => ['smallint', true, null],
        'm_score' => ['smallint', true, null],
        'rfm_score' => ['varchar(3)', true, null],
        'rfm_segment' => ['varchar(30)', true, null],
        'avg_days_between' => ['numeric(8,2)', true, null],
        'median_days_between' => ['numeric(8,2)', true, null],
        'purchase_cycle_days' => ['numeric(8,2)', true, null],
        'expected_next_order_at' => ['tstz', true, null],
        'clv_historical' => ['bigint', false, '0'],
        'clv_estimated' => ['bigint', true, null],
        'clv_confidence' => ['varchar(8)', true, null],
        'churn_risk_score' => ['numeric(5,2)', true, null],
        'churn_risk_level' => ['varchar(10)', true, null],
        'churn_reason' => ['text', true, null],
        'distinct_categories' => ['int', false, '0'],
        'cohort_month' => ['varchar(7)', true, null],
        'metric_run_id' => ['bigint', true, null],
        'computed_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty();
});

it('keys customer_metrics by customer and removes a customer\'s metrics with the customer', function () {
    expect(SchemaProbe::primaryKey('customer_metrics'))->toBe(['customer_id'])
        ->and(SchemaProbe::foreignKey('customer_metrics', 'customer_id'))->toBe(['ref_table' => 'customers', 'delete_rule' => 'CASCADE']);

    $id = metricsRow();
    DB::table('customers')->where('id', $id)->delete();

    expect(DB::table('customer_metrics')->count())->toBe(0);
});

it('links a metrics row to its run and nulls the link if the run is deleted', function () {
    expect(SchemaProbe::foreignKey('customer_metrics', 'metric_run_id'))->toBe(['ref_table' => 'metric_runs', 'delete_rule' => 'SET NULL']);

    $run = DB::table('metric_runs')->insertGetId(['mode' => 'full']);
    $id = metricsRow(['metric_run_id' => $run]);
    DB::table('metric_runs')->where('id', $run)->delete();

    expect(DB::table('customer_metrics')->where('customer_id', $id)->value('metric_run_id'))->toBeNull();
});

it('defaults a metric run to a running v1 run', function () {
    $row = DB::table('metric_runs')->find(DB::table('metric_runs')->insertGetId(['mode' => 'dirty']));

    expect($row->status)->toBe('running')->and($row->definition_version)->toBe('v1')
        ->and($row->customers_processed)->toBe(0)->and($row->finished_at)->toBeNull()->and($row->started_at)->not->toBeNull();
});

it('rejects an unknown metric run mode or status', function (array $row) {
    DB::table('metric_runs')->insert($row);
})->with([
    'mode' => [['mode' => 'partial']],
    'status' => [['mode' => 'full', 'status' => 'paused']],
])->throws(QueryException::class);

it('accepts every metric run mode and status in the PRD', function () {
    foreach (['full', 'dirty'] as $mode) {
        foreach (['running', 'completed', 'failed'] as $status) {
            DB::table('metric_runs')->insert(['mode' => $mode, 'status' => $status]);
        }
    }

    expect(DB::table('metric_runs')->count())->toBe(6);
});

it('stores the churn thresholds snapshot as JSONB', function () {
    $id = DB::table('metric_runs')->insertGetId(['mode' => 'full', 'thresholds' => json_encode(['p50' => 45.5, 'p75' => 90, 'p90' => 150])]);

    expect(DB::selectOne("select (thresholds->>'p90')::numeric as p90 from metric_runs where id = ?", [$id])->p90)->toEqual(150);
});

it('gives a new customer_metrics row the PRD defaults', function () {
    $row = DB::table('customer_metrics')->where('customer_id', metricsRow())->first();

    expect($row->total_orders)->toBe(0)->and($row->total_revenue)->toBe(0)->and($row->frequency)->toBe(0)
        ->and($row->monetary)->toBe(0)->and($row->clv_historical)->toBe(0)->and($row->distinct_categories)->toBe(0)
        ->and($row->clv_estimated)->toBeNull()->and($row->r_score)->toBeNull()->and($row->churn_risk_level)->toBeNull();
});

it('keeps r/f/m scores between 1 and 5 (NULL for customers with no orders)', function (string $column, int $value) {
    metricsRow([$column => $value]);
})->with([
    ['r_score', 0], ['r_score', 6], ['f_score', 0], ['f_score', 6], ['m_score', 0], ['m_score', 6],
])->throws(QueryException::class);

it('accepts scores 1 through 5 and NULL', function () {
    foreach ([1, 2, 3, 4, 5] as $score) {
        metricsRow(['r_score' => $score, 'f_score' => $score, 'm_score' => $score]);
    }
    metricsRow();

    expect(DB::table('customer_metrics')->count())->toBe(6);
});

it('rejects an unknown clv_confidence or churn_risk_level, accepts every PRD value', function () {
    foreach (['low', 'medium', 'high'] as $level) {
        metricsRow(['clv_confidence' => $level]);
    }
    foreach (['low', 'medium', 'high', 'lost'] as $level) {
        metricsRow(['churn_risk_level' => $level]);
    }

    expect(DB::table('customer_metrics')->count())->toBe(7);
});

it('rejects invalid clv_confidence and churn_risk_level values', function (array $row) {
    metricsRow($row);
})->with([
    'clv_confidence' => [['clv_confidence' => 'certain']],
    'churn_risk_level' => [['churn_risk_level' => 'critical']],
])->throws(QueryException::class);

it('accepts exactly the eight RFM segments from the PRD and rejects anything else', function () {
    foreach (['champion', 'loyal', 'promising', 'new_customer', 'at_risk', 'cant_lose', 'hibernating', 'lost'] as $segment) {
        metricsRow(['rfm_segment' => $segment]);
    }

    expect(DB::table('customer_metrics')->count())->toBe(8);
});

it('rejects an invented RFM segment', function () {
    metricsRow(['rfm_segment' => 'vip']);
})->throws(QueryException::class);

it('stores CLV and revenue as bigint Toman and day counts as numeric(8,2)', function () {
    $id = metricsRow(['total_revenue' => 12_000_000_000, 'clv_estimated' => 4_500_000, 'purchase_cycle_days' => 47.25]);
    $row = DB::table('customer_metrics')->where('customer_id', $id)->first();

    expect($row->total_revenue)->toBe(12_000_000_000)->and($row->clv_estimated)->toBe(4_500_000)
        ->and((float) $row->purchase_cycle_days)->toBe(47.25);
});

it('has every PRD index on customer_metrics', function () {
    $indexes = SchemaProbe::indexes('customer_metrics');

    foreach (['recency_days', 'rfm_segment', 'churn_risk_level', 'cohort_month', 'expected_next_order_at'] as $column) {
        expect(SchemaProbe::hasIndexOn('customer_metrics', $column))->toBeTrue("missing index on {$column}");
    }

    expect(SchemaProbe::hasIndexOn('customer_metrics', 'monetary DESC'))->toBeTrue()
        ->and($indexes)->not->toBeEmpty();
});
