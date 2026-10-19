<?php

declare(strict_types=1);

use App\Modules\Ai\Context\ContextBuilder;
use App\Modules\Ai\Context\ContextPiiGuard;
use App\Modules\Ai\Context\ContextTooLargeException;
use App\Modules\Ai\Context\DataFreshnessReader;
use App\Modules\Ai\Dto\ToolResult;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\ToolRegistry;
use App\Modules\Ai\Tools\Tools\GetDashboardMetricsTool;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
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
    config(['ai.context.pii_denylist' => ['Rezaei', 'علی رضایی', 'ali.rezaei'], 'ai.context.min_product_coverage_percent' => 0, 'ai.tools.affinity_min_support' => 5]);   // the fixture's purchases are a sliver of its daily revenue; coverage has its own tests
});

afterEach(function () {
    AiToolsFixture::teardown();
});

it('builds a daily_brief with the sections in a fixed order and an honest summary', function () {
    [$c, $p] = ctx();

    expect(array_keys($p))->toBe(['meta', 'data_quality', 'truncated', 'truncations', 'kpis', 'anomalies', 'trend', 'customer_health', 'retention_cohort', 'products_affinity'])
        ->and($p['meta'])->toBe(['schema' => 'analyst.context.v1', 'type' => 'daily_brief', 'currency' => 'IRT', 'money' => 'integer_toman', 'numbers_are_precomputed' => true, 'primary_window_days' => 1, 'redactions' => 0])
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
        ->and($q['sync_status_source'])->toBe('sync_cursors')->and($q['sync_failed_recently'])->toBeFalse()
        ->and(array_column($q['sync'], 'entity'))->toBe(['catalog', 'orders'])->and($q['sync'][1])->toMatchArray(['entity' => 'orders', 'last_status' => 'completed', 'consecutive_failures' => 0])
        ->and($q['sync'][1]['last_run']['at_utc'])->toBe('2026-10-13T20:05:00Z')->and($q['suspected_data_gap_events'])->toBe(0)->and($q['interpretation'])->toBe([])->and($q['unavailable_tools'])->toBe([]);
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

it('marks a zero-order anomaly as a suspected data gap when Sync itself is failing, even with fresh data and a healthy metrics run', function () {
    AiToolsFixture::loadDaily(outageRows($this->series));
    AiToolsFixture::admin()->table('sync_cursors')->where('entity', 'orders')->update(['last_status' => 'failed', 'consecutive_failures' => 3]);

    [, $p] = ctx();
    $q = $p['data_quality'];
    $zeros = array_filter($p['anomalies']['anomalies'], fn ($e) => $e['observed'] === 0);

    expect($q['stale_data'])->toBeFalse()->and($q['metrics_failed_recently'])->toBeFalse()->and($q['sync_failed_recently'])->toBeTrue()->and($q['warnings'])->toBe(['sync_failed_recently'])
        ->and($q['sync'][1])->toMatchArray(['entity' => 'orders', 'last_status' => 'failed', 'consecutive_failures' => 3])
        ->and($zeros)->not->toBeEmpty()->and(array_unique(array_column($zeros, 'suspected_data_gap')))->toBe([true]);
});

it('PII guard: customer-looking TEXT inside a data string is redacted and the run continues; nothing leaks', function (string $table, array $where, string $column, string $value) {
    AiToolsFixture::admin()->table($table)->where($where)->update([$column => $value]);

    [$c, $p] = ctx();

    expect($c->redactions)->toBeGreaterThanOrEqual(1)->and($p['meta']['redactions'])->toBe($c->redactions)->and($c->json)->toContain('[redacted]')
        ->and($c->json)->not->toContain($value)->not->toContain('09121234567')->not->toContain('ali.rezaei')->not->toContain('Rezaei')->not->toContain('۰۹۱۲۱۲۳۴۵۶۷');
    (new ContextPiiGuard)->assertClean($p);   // the verification pass finds nothing left
})->with([
    'a product named after a mobile number' => ['products', ['woo_product_id' => 11], 'name', 'Linen Dress 09121234567'],
    'a ten-digit SKU that looks like a mobile' => ['products', ['woo_product_id' => 11], 'name', 'Linen Dress SKU 9123456789'],
    'a segment named after an email' => ['segments', ['name' => 'Big spenders'], 'name', 'VIP ali.rezaei@example.com'],
    'a product named after a customer (denylist)' => ['products', ['woo_product_id' => 12], 'name', 'Silk Scarf for Rezaei'],
    'a category with a Persian-digit phone' => ['product_categories', ['woo_category_id' => 1], 'name', 'دسته ۰۹۱۲۱۲۳۴۵۶۷'],
]);

it('counts every redaction and reports nothing else about them', function () {
    AiToolsFixture::admin()->table('products')->where('woo_product_id', 11)->update(['name' => 'A 09121234567']);
    AiToolsFixture::admin()->table('products')->where('woo_product_id', 12)->update(['name' => 'B ali.rezaei@example.com']);

    [$c] = ctx();

    expect($c->redactions)->toBeGreaterThanOrEqual(2);
});

it('makes no HTTP call and uses no AI provider while building', function () {
    builder()->build(AiInsightType::DailyBrief);
    builder()->build(AiInsightType::WeeklyReview);

    Http::assertNothingSent();
});

/*
| The anchor bug (first real run): KPI tools anchored on the latest daily_metrics row, which was TODAY's unfinished one, while
| freshness/anomalies said "yesterday". Period::end() is now the single anchor: the latest row on or before yesterday (Tehran).
*/
function addPartialToday(): void
{
    $db = AiToolsFixture::admin();
    $row = (array) $db->table('daily_metrics')->where('date', '2026-10-13')->first();
    $db->table('daily_metrics')->insert(array_replace($row, ['date' => '2026-10-14', 'jalali_date' => '1405/07/22', 'orders_count' => 1, 'net_revenue' => 50_000, 'revenue' => 50_000, 'product_revenue' => 50_000, 'aov' => 50_000]));
}

it('ignores the incomplete today row: every KPI window and the trend end on the same complete day as as_of', function () {
    addPartialToday();
    [, $p] = ctx();

    expect($p['data_quality']['as_of']['iso'])->toBe('2026-10-13')
        ->and($p['kpis']['windows'][0]['result']['period']['to']['iso'])->toBe('2026-10-13')
        ->and($p['kpis']['windows'][1]['result']['period']['to']['iso'])->toBe('2026-10-13')
        ->and($p['trend']['to']['iso'])->toBe('2026-10-13')
        ->and($p['data_quality']['period_anchor'])->toBe(['as_of' => '2026-10-13', 'kpi_and_trend_end' => ['2026-10-13'], 'mismatch' => false])
        ->and($p['data_quality']['warnings'])->toBe([]);
});

it('anchors the anomaly detector on the same complete day, not on today', function () {
    addPartialToday();
    [, $p] = ctx();

    expect(json_encode($p['anomalies']))->not->toContain('2026-10-14')->and($p['anomalies']['status'])->toBe('ok');
});

it('uses the latest complete day when yesterday has no row (never rolls forward to today)', function () {
    addPartialToday();
    AiToolsFixture::admin()->table('daily_metrics')->where('date', '2026-10-13')->delete();
    [, $p] = ctx();

    expect($p['kpis']['windows'][0]['result']['period']['to']['iso'])->toBe('2026-10-12')->and($p['data_quality']['as_of']['iso'])->toBe('2026-10-12');
});

it('NEVER stays silent when a KPI window ends on a different day than as_of: period_anchor_mismatch is a warning', function () {
    $drifting = new class(app(GetDashboardMetricsTool::class)) extends AiTool
    {
        public function __construct(private readonly GetDashboardMetricsTool $real) {}

        public function name(): string
        {
            return $this->real->name();
        }

        public function description(): string
        {
            return $this->real->description();
        }

        public function properties(): array
        {
            return $this->real->properties();
        }

        public function required(): array
        {
            return $this->real->required();
        }

        public function rules(): array
        {
            return $this->real->rules();
        }

        public function run(array $arguments): array
        {
            $r = $this->real->run($arguments);
            $r['period']['to']['iso'] = '2026-10-14';   // a tool reading a different day than the freshness block

            return $r;
        }
    };
    $container = new Container;
    $container->bind(GetDashboardMetricsTool::class, fn () => $drifting);
    $builder = new ContextBuilder(new ToolRegistry(new class($container, app()) extends Container
    {
        public function __construct(private readonly Container $overrides, private readonly Application $app) {}

        public function make($abstract, array $parameters = [])
        {
            return $this->overrides->bound($abstract) ? $this->overrides->make($abstract) : $this->app->make($abstract, $parameters);
        }
    }), app(DataFreshnessReader::class), app(ContextPiiGuard::class));
    $p = json_decode($builder->build(AiInsightType::DailyBrief)->json, true);

    expect($p['data_quality']['warnings'])->toContain('period_anchor_mismatch')
        ->and($p['data_quality']['period_anchor']['mismatch'])->toBeTrue()
        ->and($p['data_quality']['period_anchor']['kpi_and_trend_end'])->toContain('2026-10-14')
        ->and($p['data_quality']['interpretation'])->toHaveKey('period_anchor_mismatch');
});

/*
| P7-08b — product coverage: the share of product revenue attributed to a product is reported, and below the threshold it is a permanent
| warning (`product_coverage_low`) that also reaches the model's interpretation block.
*/
it('reports product coverage and raises product_coverage_low below the threshold', function () {
    $attributed = (int) AiToolsFixture::admin()->table('customer_product_purchases')->sum('revenue');
    $total = (int) AiToolsFixture::admin()->table('daily_metrics')->sum('product_revenue');
    config(['ai.context.min_product_coverage_percent' => 90]);
    [, $p] = ctx();
    $q = $p['data_quality'];

    expect($q['product_coverage']['attributed_percent'])->toBe(round($attributed / $total * 100, 2))
        ->and($q['product_coverage']['unattributed_percent'])->toBe(round(100 - round($attributed / $total * 100, 2), 2))
        ->and($q['product_coverage']['min_percent'])->toEqual(90)
        ->and($q['warnings'])->toContain('product_coverage_low')->and($q['interpretation'])->toHaveKey('product_coverage_low');
});

it('raises no coverage warning when coverage meets the threshold or the check is disabled (0)', function () {
    config(['ai.context.min_product_coverage_percent' => 0]);
    [, $p] = ctx();

    expect($p['data_quality']['warnings'])->toBe([])->and($p['data_quality']['product_coverage']['attributed_percent'])->toBeFloat();

    config(['ai.context.min_product_coverage_percent' => 0.0001]);
    [, $p] = ctx();

    expect($p['data_quality']['warnings'])->toBe([]);
});

it('states the period of top_products explicitly and carries support and the min support into the affinity section', function () {
    [, $p] = ctx();

    expect($p['products_affinity']['top_products']['period']['basis'])->toBe('lifetime_all_orders')
        ->and($p['products_affinity']['affinity_product'])->toHaveKeys(['min_support', 'support_means'])->and($p['products_affinity']['affinity_product']['pairs'][0])->toHaveKey('support')
        ->and($p['customer_health']['churn'])->toHaveKeys(['value_at_risk_means', 'value_at_risk_clv_confidence'])
        ->and($p['kpis']['windows'][0]['result']['customer_base'])->toHaveKeys(['one_time_customers_percent', 'repeat_rate_means']);
});

/*
| P7-12 — holes in daily_metrics: a day nobody computed is not "no sales". Missing days (count + ranges) and zero-order rows inside the
| baseline window raise `daily_metrics_gap`, and every anomaly in that window becomes a suspected data gap.
*/
it('reports no gaps for a continuous history, and does not count the days before the table starts', function () {
    [, $p] = ctx();
    $g = $p['data_quality']['daily_metrics_gaps'];

    expect($g)->toMatchArray(['window_days' => 56, 'missing_days' => 0, 'missing_ranges' => [], 'zero_order_days' => 0, 'window_to' => '2026-10-13'])
        ->and($p['data_quality']['warnings'])->toBe([]);

    AiToolsFixture::admin()->table('daily_metrics')->where('date', '<', '2026-09-30')->delete();   // history simply starts later
    [, $p] = ctx();

    expect($p['data_quality']['daily_metrics_gaps'])->toMatchArray(['window_from' => '2026-09-30', 'missing_days' => 0])->and($p['data_quality']['warnings'])->toBe([]);
});

it('flags missing days with their ranges, raises daily_metrics_gap and marks every anomaly a suspected data gap', function () {
    AiToolsFixture::admin()->table('daily_metrics')->whereBetween('date', ['2026-10-02', '2026-10-05'])->delete();
    AiToolsFixture::admin()->table('daily_metrics')->where('date', '2026-10-09')->delete();
    AiToolsFixture::admin()->table('daily_metrics')->where('date', '2026-10-13')->update(['orders_count' => 130, 'net_revenue' => 26_000_000, 'revenue' => 26_000_000]);   // an anomaly inside the 3-day look-back
    [$c, $p] = ctx();
    $q = $p['data_quality'];

    expect($q['daily_metrics_gaps']['missing_days'])->toBe(5)
        ->and($q['daily_metrics_gaps']['missing_ranges'])->toBe([['from' => '2026-10-02', 'to' => '2026-10-05', 'days' => 4], ['from' => '2026-10-09', 'to' => '2026-10-09', 'days' => 1]])
        ->and($q['warnings'])->toContain('daily_metrics_gap')->and($q['interpretation'])->toHaveKey('daily_metrics_gap')
        ->and($q['suspected_data_gap_events'])->toBeGreaterThan(0)
        ->and(array_unique(array_column($p['anomalies']['anomalies'], 'suspected_data_gap')))->toBe([true]);
});

it('flags zero-order rows (a stale zero is as bad as a missing row) once they reach the configured count', function () {
    AiToolsFixture::admin()->table('daily_metrics')->whereBetween('date', ['2026-10-01', '2026-10-03'])->update(['orders_count' => 0, 'net_revenue' => 0, 'revenue' => 0]);
    [, $p] = ctx();

    expect($p['data_quality']['daily_metrics_gaps']['zero_order_days'])->toBe(3)->and($p['data_quality']['daily_metrics_gaps']['missing_days'])->toBe(0)
        ->and($p['data_quality']['warnings'])->toContain('daily_metrics_gap');

    config(['ai.context.zero_days_warn_at' => 4]);
    [, $p] = ctx();

    expect($p['data_quality']['warnings'])->not->toContain('daily_metrics_gap');
});
