<?php

declare(strict_types=1);

use App\Modules\Ai\Context\ContextBuilder;
use App\Modules\Ai\Context\ContextPiiException;
use App\Modules\Ai\Context\ContextTooLargeException;
use App\Modules\Ai\Dto\ToolResult;
use App\Modules\Ai\Enums\AiInsightType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiToolsFixture;
use Tests\Support\AnomalySeries;

/*
| P7-06 — ContextBuilder over REAL PostgreSQL through the real `ai_readonly` role. The whole fixture (customers with planted fake
| PII, metrics, 130 daily rows with an injected spike, cohorts, products, segments) is committed through ai_admin.
| 2026-10-14 is a Wednesday; "today" is pinned there so as_of = 2026-10-13 and data_age_days = 0 unless a test moves the clock.
*/

const CTX_SPIKE_DAY = '2026-10-07';

function builder(): ContextBuilder
{
    return app(ContextBuilder::class);
}

function ctx(AiInsightType $type = AiInsightType::DailyBrief, ?int $days = null): array
{
    $c = builder()->build($type, $days);

    return [$c, json_decode($c->json, true)];
}

beforeEach(function () {
    $skip = AiToolsFixture::boot();

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Tehran'));
    AiToolsFixture::seed();
    $this->series = AnomalySeries::make('2026-06-01', '2026-10-13', [CTX_SPIKE_DAY => ['orders_count' => 130, 'net_revenue' => 26_000_000]]);
    AiToolsFixture::loadDaily($this->series);
    config(['ai.context.pii_denylist' => ['Rezaei', 'علی رضایی', 'ali.rezaei']]);
});

afterEach(function () {
    AiToolsFixture::teardown();
});

it('builds a daily_brief with the sections in a fixed order and an honest summary', function () {
    [$c, $p] = ctx();

    expect(array_keys($p))->toBe(['meta', 'data_quality', 'truncated', 'truncations', 'kpis', 'anomalies', 'trend', 'customer_health', 'retention_cohort', 'products_affinity'])
        ->and($p['meta'])->toBe(['schema' => 'analyst.context.v1', 'type' => 'daily_brief', 'currency' => 'IRT', 'money' => 'integer_toman', 'numbers_are_precomputed' => true, 'primary_window_days' => 1])
        ->and($c->type)->toBe('daily_brief')->and($c->truncated)->toBeFalse()->and($c->truncations)->toBe([])
        ->and($c->sizeBytes)->toBe(strlen($c->json))->and($c->maxBytes)->toBe(24 * 1024)->and($c->sizeBytes)->toBeLessThan($c->maxBytes)
        ->and($c->estimatedTokens)->toBe((int) ceil($c->sizeBytes / 3))
        ->and(array_keys($c->sectionBytes))->toBe(array_keys($p))
        ->and(array_map(fn ($w) => $w['days'], $p['kpis']['windows']))->toBe([1, 7])
        ->and($p['trend']['granularity'])->toBe('day');
});

it('builds a weekly_review with its own windows and weekly trend, and refuses unsupported types and ranges', function () {
    [, $p] = ctx(AiInsightType::WeeklyReview);

    expect(array_map(fn ($w) => $w['days'], $p['kpis']['windows']))->toBe([7, 28])->and($p['trend']['granularity'])->toBe('week')->and($p['meta']['primary_window_days'])->toBe(7)
        ->and(fn () => builder()->build(AiInsightType::Explain))->toThrow(InvalidArgumentException::class)
        ->and(fn () => builder()->build(AiInsightType::DailyBrief, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => builder()->build(AiInsightType::DailyBrief, 91))->toThrow(InvalidArgumentException::class);
});

it('precomputes the period comparison correctly (checked against an independent sum of the fixture rows)', function () {
    [, $p] = ctx(AiInsightType::WeeklyReview);
    $sum = function (string $from, string $to, string $col) {
        return array_sum(array_column(array_filter($this->series, fn ($r) => $r['date'] >= $from && $r['date'] <= $to), $col));
    };
    $week = $p['kpis']['windows'][0]['result'];

    $orders = $sum('2026-10-07', '2026-10-13', 'orders_count');
    $prevOrders = $sum('2026-09-30', '2026-10-06', 'orders_count');
    $net = $sum('2026-10-07', '2026-10-13', 'net_revenue');
    $prevNet = $sum('2026-09-30', '2026-10-06', 'net_revenue');

    expect($week['current']['orders'])->toBe($orders)->and($week['previous']['orders'])->toBe($prevOrders)
        ->and($week['current']['net_revenue'])->toBe($net)->and($week['previous']['net_revenue'])->toBe($prevNet)
        ->and($week['change_percent']['orders'])->toBe(round(($orders - $prevOrders) / $prevOrders * 100, 2))
        ->and($week['change_percent']['net_revenue'])->toBe(round(($net - $prevNet) / $prevNet * 100, 2))
        ->and($week['period']['to']['iso'])->toBe('2026-10-13')->and($week['period']['to']['jalali'])->toBe('1405/07/21')
        ->and($p['kpis']['windows'][0]['result'])->toHaveKey('customer_base')->and($p['kpis']['windows'][1]['result'])->not->toHaveKey('customer_base');
});

it('reports freshness for a healthy, up-to-date pipeline', function () {
    [, $p] = ctx();
    $q = $p['data_quality'];

    expect($q['as_of'])->toBe(['iso' => '2026-10-13', 'jalali' => '1405/07/21'])->and($q['data_age_days'])->toBe(0)->and($q['stale_data'])->toBeFalse()->and($q['stale_after_days'])->toBe(1)
        ->and($q['warnings'])->toBe([])->and($q['metrics_failed_recently'])->toBeFalse()->and($q['metrics_last_success']['mode'])->toBe('full')
        ->and($q['metrics_last_success']['at_utc'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')->and($q['pipeline_last_run']['tehran']['jalali'])->toMatch('/^\d{4}\/\d\d\/\d\d$/')
        ->and($q['sync_status_source'])->toBe('inferred_from_pipeline_last_run')->and($q['suspected_data_gap_events'])->toBe(0)->and($q['interpretation'])->toBe([])->and($q['unavailable_tools'])->toBe([]);
});

it('flags stale_data with the right data_age_days, and honours the configurable threshold', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Tehran'));   // yesterday = 10-17, newest complete row = 10-13

    [, $p] = ctx();
    expect($p['data_quality']['data_age_days'])->toBe(4)->and($p['data_quality']['stale_data'])->toBeTrue()->and($p['data_quality']['warnings'])->toBe(['stale_data'])
        ->and($p['data_quality']['interpretation'])->toHaveKey('stale_data');

    config(['ai.context.stale_after_days' => 5]);
    [, $tolerant] = ctx();
    expect($tolerant['data_quality']['stale_data'])->toBeFalse()->and($tolerant['data_quality']['data_age_days'])->toBe(4);
});

function outageRows(array $series): array   // the last two complete days show ZERO orders — a dead sync or a dead shop?
{
    return AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-10-12' => $z = ['orders_count' => 0, 'net_revenue' => 0, 'customers_new' => 0, 'customers_repeat' => 0, 'customers_total' => 0, 'revenue' => 0, 'refunds' => 0], '2026-10-13' => $z]);
}

it('marks a zero-order anomaly as a suspected data gap when the data is stale', function () {
    AiToolsFixture::loadDaily(outageRows($this->series));
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00', 'Asia/Tehran'));   // 2 days stale

    [, $p] = ctx();
    $zeros = array_values(array_filter($p['anomalies']['anomalies'], fn ($e) => $e['observed'] === 0));

    expect($p['data_quality']['stale_data'])->toBeTrue()->and($zeros)->not->toBeEmpty()
        ->and(array_unique(array_column($zeros, 'suspected_data_gap')))->toBe([true])   // kept, labelled — never dropped
        ->and($p['data_quality']['suspected_data_gap_events'])->toBe(count($zeros))
        ->and($p['data_quality']['interpretation']['suspected_data_gap'])->toBe('zero_values_may_be_missing_data_not_business_decline');
});

it('marks it too when the latest metrics run FAILED, even though the data looks fresh', function () {
    AiToolsFixture::loadDaily(outageRows($this->series));
    AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'full', 'status' => 'failed', 'error' => 'boom', 'started_at' => now(), 'finished_at' => now()]);

    [, $p] = ctx();
    $zeros = array_filter($p['anomalies']['anomalies'], fn ($e) => $e['observed'] === 0);

    expect($p['data_quality']['stale_data'])->toBeFalse()->and($p['data_quality']['metrics_failed_recently'])->toBeTrue()->and($p['data_quality']['warnings'])->toBe(['metrics_run_failed_recently'])
        ->and($p['data_quality']['metrics_last_failure']['mode'])->toBe('full')->and($zeros)->not->toBeEmpty()
        ->and(array_unique(array_column($zeros, 'suspected_data_gap')))->toBe([true]);
});

it('treats the same zero-order anomaly as an ordinary anomaly when data is fresh and the pipeline healthy', function () {
    AiToolsFixture::loadDaily(outageRows($this->series));

    [, $p] = ctx();
    $zeros = array_filter($p['anomalies']['anomalies'], fn ($e) => $e['observed'] === 0);

    expect($p['data_quality']['stale_data'])->toBeFalse()->and($p['data_quality']['metrics_failed_recently'])->toBeFalse()->and($zeros)->not->toBeEmpty()
        ->and(array_unique(array_column($zeros, 'suspected_data_gap')))->toBe([false])->and($p['data_quality']['suspected_data_gap_events'])->toBe(0);
});

it('does not call a failed run "recent" once a newer run succeeded', function () {
    AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'full', 'status' => 'failed', 'error' => 'old', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
    AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'dirty', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);

    [, $p] = ctx();

    expect($p['data_quality']['metrics_failed_recently'])->toBeFalse()->and($p['data_quality']['metrics_last_failure'])->not->toBeNull()->and($p['data_quality']['metrics_last_success']['mode'])->toBe('dirty');
});

it('never exceeds the size cap, whatever the cap, and cuts the lowest-priority sections first', function () {
    [$full] = ctx();
    $order = ['products_affinity', 'retention_cohort', 'customer_health', 'trend', 'kpi_secondary', 'anomalies'];
    $previousCuts = 0;
    $seenSections = [];

    foreach (range(0, 40) as $step) {
        $cap = (int) floor($full->sizeBytes * (1 - $step * 0.024));
        config(['ai.context.max_kb' => $cap / 1024]);

        try {
            [$c, $p] = ctx();
        } catch (ContextTooLargeException) {
            $tooSmall = true;

            break;
        }

        // Invariants at EVERY cap:
        expect($c->sizeBytes)->toBeLessThanOrEqual($c->maxBytes)->and($c->maxBytes)->toBeLessThanOrEqual($cap + 1)
            ->and($p)->toHaveKeys(['meta', 'data_quality', 'truncated', 'truncations', 'kpis', 'anomalies'])        // never removed
            ->and($p['data_quality']['as_of'])->not->toBeNull()
            ->and($p['kpis']['windows'][0]['result'])->toHaveKey('current')
            ->and($p['anomalies'])->toHaveKey('anomalies')->and($c->truncated)->toBe($p['truncated'])->and($p['truncations'])->toBe($c->truncations);

        $sections = array_column($c->truncations, 'section');
        $cuts = count($c->truncations);
        expect($cuts)->toBeGreaterThanOrEqual($previousCuts);   // a smaller cap never cuts less
        $previousCuts = $cuts;

        // Order: a section is touched only if every lower-priority section is already fully cut.
        foreach ($sections as $s) {
            $lower = array_slice($order, 0, array_search($s, $order, true));

            foreach ($lower as $l) {
                $entry = collect($c->truncations)->firstWhere('section', $l);
                expect($entry)->not->toBeNull("{$s} was cut before {$l} was exhausted")
                    ->and($entry['action'] === 'removed' || ($l === 'anomalies'))->toBeTrue();
            }

            $seenSections[$s] = true;
        }
    }

    expect($seenSections)->toHaveKeys(['products_affinity', 'retention_cohort', 'customer_health', 'trend'])->and($previousCuts)->toBeGreaterThan(3);
});

it('flags a cut inside the context itself, and says what was cut', function () {
    [$full] = ctx();
    config(['ai.context.max_kb' => ($full->sizeBytes - 100) / 1024]);

    [$c, $p] = ctx();

    expect($c->truncated)->toBeTrue()->and($p['truncated'])->toBeTrue()->and($c->truncations[0])->toMatchArray(['section' => 'products_affinity', 'action' => 'reduced'])
        ->and($c->truncations[0]['detail'])->toBe('level 1 of 3')->and($c->sizeBytes)->toBeLessThan($full->sizeBytes);
});

it('refuses (throws) instead of silently dropping the irreducible core', function () {
    config(['ai.context.max_kb' => 0.3]);   // ~307 bytes: smaller than meta + data_quality alone

    expect(fn () => ctx())->toThrow(ContextTooLargeException::class);
});

it('is deterministic: same data, same bytes — and the physical row order of the tables does not matter', function () {
    [$a] = ctx();
    [$b] = ctx();

    expect($b->json)->toBe($a->json)->and(hash('sha256', $b->json))->toBe(hash('sha256', $a->json));

    $db = AiToolsFixture::admin();

    foreach (['customer_metrics' => 'customer_id', 'daily_metrics' => 'date', 'cohort_snapshots' => 'id', 'product_affinities' => 'id', 'customer_product_purchases' => 'customer_id'] as $table => $key) {
        $rows = $db->table($table)->orderByDesc($key)->get()->map(fn ($r) => (array) $r)->all();
        $db->table($table)->delete();

        foreach ($rows as $row) {
            $db->table($table)->insert($row);
        }
    }

    [$c] = ctx();

    expect($c->json)->toBe($a->json);
});

it('returns the ToolResults whose output is inside the context, ready for ai_tool_calls', function () {
    [$c, $p] = ctx();
    $names = array_map(fn (ToolResult $r) => $r->name, $c->toolResults);

    expect($c->toolResults)->not->toBeEmpty()->and(array_unique(array_map(fn ($r) => $r::class, $c->toolResults)))->toBe([ToolResult::class])
        ->and(array_filter($c->toolResults, fn (ToolResult $r) => $r->failed()))->toBe([])
        ->and(array_count_values($names)['get_dashboard_metrics'])->toBe(2)
        ->and($names)->toContain('get_anomalies', 'get_sales_trend', 'get_rfm_distribution', 'get_churn_report', 'get_segment_summary', 'get_customer_summary', 'get_retention_report', 'get_cohort_report', 'get_top_products', 'get_product_affinity')
        ->and(collect($c->toolResults)->firstWhere('name', 'get_anomalies')->result['status'])->toBe($p['anomalies']['status'])
        ->and(collect($c->toolResults)->every(fn (ToolResult $r) => $r->resultSize === strlen(json_encode($r->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))))->toBeTrue();

    config(['ai.context.max_kb' => ($c->sizeBytes - 100) / 1024]);
    [$cut] = ctx();

    expect(array_map(fn ($r) => $r->name.json_encode($r->arguments), $cut->toolResults))->not->toEqual(array_map(fn ($r) => $r->name.json_encode($r->arguments), $c->toolResults));   // only what is IN the cut context
});

it('PII guard: a customer detail that slips into a section makes the build throw and return nothing', function (string $table, array $where, string $column, string $value) {
    AiToolsFixture::admin()->table($table)->where($where)->update([$column => $value]);

    $result = null;

    try {
        $result = builder()->build(AiInsightType::DailyBrief);
        $this->fail('expected ContextPiiException');
    } catch (ContextPiiException $e) {
        expect($e->getMessage())->toContain('Context refused')->and($e->getMessage())->not->toContain($value);
    }

    expect($result)->toBeNull();
})->with([
    'a product named after a mobile number' => ['products', ['woo_product_id' => 11], 'name', 'Linen Dress 09121234567'],
    'a segment named after an email' => ['segments', ['name' => 'Big spenders'], 'name', 'VIP ali.rezaei@example.com'],
    'a product named after a customer (denylist)' => ['products', ['woo_product_id' => 12], 'name', 'Silk Scarf for Rezaei'],
    'a category with a Persian-digit phone' => ['product_categories', ['woo_category_id' => 1], 'name', 'دسته ۰۹۱۲۱۲۳۴۵۶۷'],
]);

it('makes no HTTP call and uses no AI provider while building', function () {
    builder()->build(AiInsightType::DailyBrief);
    builder()->build(AiInsightType::WeeklyReview);

    Http::assertNothingSent();
});
