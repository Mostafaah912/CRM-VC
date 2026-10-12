<?php

declare(strict_types=1);

use App\Modules\Ai\Services\AnomalySource;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolCatalog;
use App\Modules\Ai\Tools\ToolRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AiToolsFixture;

/*
| P7-04 — the 12 read-only tools against REAL PostgreSQL, through the real `ai_readonly` role (hm_ai_readonly), over a
| small committed fixture (tests/Support/AiToolsFixture.php). Skips, like P7-01, when ai_admin cannot create the role
| / write the test database. GATE 4 ("no tool leaks PII") is the scan test at the bottom.
*/

beforeEach(function () {
    $skip = AiToolsFixture::boot();

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    $this->fx = AiToolsFixture::seed();
    $this->tools = app(ToolRegistry::class);
});

afterEach(function () {
    AiToolsFixture::teardown();
});

function tool(string $name, array $args = []): array
{
    $r = app(ToolRegistry::class)->execute($name, $args);
    expect($r->error)->toBeNull("{$name} failed: {$r->error}");

    return $r->result;
}

it('exposes exactly the 12 PRD §19 tools', function () {
    expect($this->tools->names())->toBe([
        'get_dashboard_metrics', 'get_sales_trend', 'get_rfm_distribution', 'get_churn_report', 'get_retention_report', 'get_cohort_report',
        'get_product_affinity', 'get_top_products', 'get_segment_summary', 'get_customer_metrics_distribution', 'get_anomalies', 'get_customer_summary',
    ])->and(count(ToolCatalog::TOOLS))->toBe(12);
});

it('get_dashboard_metrics: window sums, previous-window comparison, customer base', function () {
    $r = tool('get_dashboard_metrics');

    expect($r['period']['days'])->toBe(30)
        ->and($r['period']['to'])->toBe(['iso' => '2026-10-14', 'jalali' => '1405/07/22'])
        ->and($r['period']['from']['iso'])->toBe('2026-09-15')
        ->and($r['current'])->toBe([
            'orders' => 60, 'revenue' => 6_300_000, 'refunds' => 300_000, 'net_revenue' => 6_000_000, 'product_revenue' => 5_400_000,
            'shipping_revenue' => 600_000, 'aov' => 100_000, 'customer_days_new' => 30, 'customer_days_repeat' => 30, 'revenue_new' => 3_000_000, 'revenue_repeat' => 3_000_000,
        ])
        ->and($r['previous']['orders'])->toBe(20)->and($r['previous']['net_revenue'])->toBe(2_000_000)
        ->and($r['change_percent'])->toBe(['orders' => 200.0, 'net_revenue' => 200.0, 'aov' => 0.0])
        ->and($r['customer_base'])->toBe(['customers_with_orders' => 12, 'repeat_customers' => 10, 'repeat_rate_percent' => 83.33]);
});

it('get_sales_trend: day / week / month buckets conserve the totals', function () {
    $day = tool('get_sales_trend', ['days' => 14]);
    $week = tool('get_sales_trend', ['days' => 40, 'granularity' => 'week']);
    $month = tool('get_sales_trend', ['days' => 40, 'granularity' => 'month']);

    expect($day['points'])->toHaveCount(14)
        ->and($day['points'][0])->toMatchArray(['orders' => 2, 'net_revenue' => 200_000, 'aov' => 100_000, 'customers_new' => 1])
        ->and(array_sum(array_column($week['points'], 'orders')))->toBe(80)
        ->and(count($week['points']))->toBeBetween(6, 7)
        ->and(array_sum(array_column($month['points'], 'net_revenue')))->toBe(8_000_000)
        ->and($month['points'][0]['bucket'])->toMatch('/^1405-\d\d$/');
});

it('get_rfm_distribution: exact numbers; loyal (4) and at_risk (2) are each under 5, so they fold into one merged row', function () {
    $r = tool('get_rfm_distribution');

    expect($r['scored_customers'])->toBe(12)
        ->and($r['segments'][0])->toBe(['segment' => 'champion', 'customers' => 6, 'share_percent' => 50.0, 'avg_recency_days' => 10, 'avg_frequency' => 4.0, 'avg_monetary' => 1_000_000, 'total_revenue' => 6_000_000])
        ->and($r['segments'][1])->toBe(['segment' => 'other_small_groups', 'customers' => 6, 'merged_groups' => 2])
        ->and($r['segments'])->toHaveCount(2)
        ->and(json_encode($r))->not->toContain('at_risk')->not->toContain('loyal');
});

it('get_churn_report: levels, merged small groups, hidden tiny value-at-risk, thresholds whitelist', function () {
    $r = tool('get_churn_report');

    expect($r['classified_customers'])->toBe(12)
        ->and($r['levels'][0])->toMatchArray(['level' => 'low', 'customers' => 7, 'share_percent' => 58.33, 'avg_risk_score' => 15.7, 'avg_recency_days' => 19])
        ->and($r['levels'][1])->toBe(['level' => 'other_small_groups', 'customers' => 5, 'merged_groups' => 2])
        ->and($r['value_at_risk'])->toBeNull()
        ->and($r['thresholds_days'])->toBe(['p50' => 30, 'p75' => 60, 'p90' => 90]);
});

it('get_retention_report: pools mature periods of large-enough cohorts only', function () {
    $r = tool('get_retention_report');

    expect($r['cohorts_considered'])->toBe(2)
        ->and($r['periods'])->toBe([['period' => 1, 'cohorts' => 1, 'cohort_customers' => 10, 'retention_percent' => 50.0]]);
});

it('get_cohort_report: matrix with immature periods null and the 3-customer cohort hidden', function () {
    $r = tool('get_cohort_report');

    expect($r['cohorts'])->toBe([
        ['cohort_month' => '1405-01', 'cohort_size' => 10, 'retention_percent_by_period' => [1 => 50.0, 2 => null], 'cumulative_revenue' => 1_000_000],
        ['cohort_month' => '1405-02', 'cohort_size' => '<5', 'retention_percent_by_period' => null, 'cumulative_revenue' => null],
    ]);
});

it('get_product_affinity: by level, with names, never a pair under 5', function () {
    $product = tool('get_product_affinity');
    $category = tool('get_product_affinity', ['level' => 'category']);
    $basket = tool('get_product_affinity', ['level' => 'basket']);

    expect(array_column($product['pairs'], 'lift'))->toBe([3.1, 1.4])   // the lift-9.9 pair has only 3 co-buyers
        ->and($product['pairs'][0]['a']['name'])->toBe('Linen Dress')->and($product['pairs'][0]['b']['name'])->toBe('Silk Scarf')
        ->and($category['pairs'][0]['a']['name'])->toBe('Dresses')
        ->and($basket['unit'])->toBe('baskets')->and($basket['pairs'][0]['co_count'])->toBe(12);
});

it('get_top_products: ranking by revenue/units/buyers, share, and products with <5 buyers hidden', function () {
    $r = tool('get_top_products');
    $byBuyers = tool('get_top_products', ['by' => 'customers', 'limit' => 1]);

    expect(array_column($r['products'], 'name'))->toBe(['Linen Dress', 'Silk Scarf'])   // "Rare Coat" has 2 buyers
        ->and($r['products'][0])->toMatchArray(['buyers' => 6, 'units' => 12, 'revenue' => 600_000, 'revenue_share_percent' => 22.64])
        ->and($byBuyers['products'])->toHaveCount(1)->and($byBuyers['products'][0]['name'])->toBe('Linen Dress');
});

it('get_segment_summary: stats for a large segment, "<5" and nothing else for a tiny one', function () {
    $r = tool('get_segment_summary');
    $one = tool('get_segment_summary', ['segment_id' => $this->fx['tiny_segment']]);

    expect($r['segments'][0])->toMatchArray(['name' => 'Big spenders', 'members' => 6, 'avg_recency_days' => 10, 'avg_monetary' => 1_000_000, 'total_revenue' => 6_000_000])
        ->and($r['segments'][0]['last_evaluated']['iso'])->toBe('2026-10-14')
        ->and($r['segments'][1])->toMatchArray(['name' => 'Tiny', 'members' => '<5', 'avg_recency_days' => null, 'avg_monetary' => null, 'total_revenue' => null])
        ->and($one['segments'])->toHaveCount(1);
});

it('get_customer_metrics_distribution: percentiles are exact', function () {
    $r = tool('get_customer_metrics_distribution');   // monetary

    expect($r)->toBe(['metric' => 'monetary', 'unit' => 'toman', 'customers' => 12, 'min' => 100_000, 'p10' => 130_000, 'p25' => 400_000, 'median' => 700_000, 'p75' => 1_000_000, 'p90' => 1_000_000, 'max' => 1_000_000, 'mean' => 650_000])
        ->and(tool('get_customer_metrics_distribution', ['metric' => 'frequency'])['max'])->toBe(4.0);
});

it('get_customer_metrics_distribution and get_customer_summary withhold a population under 5', function () {
    $db = AiToolsFixture::admin();
    $keep = $db->table('customer_metrics')->orderBy('customer_id')->limit(3)->pluck('customer_id')->all();
    $db->table('customer_metrics')->whereNotIn('customer_id', $keep)->delete();

    expect(tool('get_customer_metrics_distribution'))->toBe(['metric' => 'monetary', 'customers' => '<5'])
        ->and(tool('get_customer_summary'))->toBe(['customers_with_orders' => '<5']);
});

it('get_customer_summary: whole-base numbers, never an individual', function () {
    $r = tool('get_customer_summary');

    expect($r)->toMatchArray([
        'customers_with_orders' => 12, 'one_time_customers' => 2, 'repeat_customers' => 10, 'repeat_rate_percent' => 83.33, 'avg_orders' => 2.83,
        'avg_aov' => 208_333, 'avg_clv_historical' => 650_000, 'total_revenue' => 7_800_000, 'avg_recency_days' => 38,
    ])->and($r['clv_confidence'][0])->toBe(['level' => 'high', 'customers' => 10])
        ->and($r['clv_confidence'][1])->toBe(['level' => 'other_small_groups', 'customers' => '<5', 'merged_groups' => 1]);
});

it('get_anomalies: reports "no detector" until P7-05, then passes through only whitelisted fields', function () {
    expect(tool('get_anomalies'))->toBe(['detector_available' => false, 'anomalies' => []]);

    app()->instance(AnomalySource::class, new class implements AnomalySource
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function recent(int $days): array
        {
            return [['date' => '2026-10-13', 'metric' => 'orders_count', 'observed' => 1, 'expected' => 9, 'deviation_percent' => -88.9, 'direction' => 'down', 'severity' => 'high', 'description' => 'drop', 'customer_id' => 5, 'phone' => '09121234567']];
        }
    });

    $r = (new ToolRegistry(app()))->execute('get_anomalies', []);

    expect($r->result['anomalies'][0])->toHaveKeys(['date', 'metric', 'observed', 'severity'])->not->toHaveKeys(['customer_id', 'phone']);
});

it('GATE 4 — no tool leaks PII: every tool, several argument sets, scanned for names, phones (both digit systems), emails, addresses and customer keys', function () {
    $calls = [
        ['get_dashboard_metrics', []], ['get_dashboard_metrics', ['days' => 366]], ['get_sales_trend', ['days' => 31]], ['get_sales_trend', ['days' => 182, 'granularity' => 'week']],
        ['get_sales_trend', ['days' => 366, 'granularity' => 'month']], ['get_rfm_distribution', []], ['get_churn_report', []], ['get_retention_report', ['max_period' => 12, 'last_cohorts' => 24]],
        ['get_cohort_report', ['last_cohorts' => 12, 'max_period' => 12]], ['get_product_affinity', ['level' => 'product', 'limit' => 15]], ['get_product_affinity', ['level' => 'category']],
        ['get_product_affinity', ['level' => 'variation']], ['get_product_affinity', ['level' => 'basket']], ['get_top_products', ['limit' => 15]], ['get_top_products', ['by' => 'units']],
        ['get_segment_summary', ['limit' => 30]], ['get_customer_metrics_distribution', ['metric' => 'recency_days']], ['get_customer_metrics_distribution', ['metric' => 'aov']],
        ['get_customer_metrics_distribution', ['metric' => 'clv_historical']], ['get_anomalies', []], ['get_customer_summary', []],
    ];
    $out = [];

    foreach ($calls as [$name, $args]) {
        $r = $this->tools->execute($name, $args);
        expect($r->error)->toBeNull("{$name}: {$r->error}");
        $out[] = $r->toModelJson();
    }

    $all = implode("\n", $out);

    foreach (AiToolsFixture::PII as $needle) {
        expect($all)->not->toContain($needle);
    }

    expect(preg_match('/(?:\+?98|0)?9\d{9}/', $all))->toBe(0)                     // Iranian mobile, ASCII digits
        ->and(preg_match('/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', $all))->toBe(0)   // Persian / Arabic-Indic digits at all
        ->and(preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $all))->toBe(0)                  // email
        ->and(preg_match('/"(customer_id|customer_ids|phone|mobile|email|first_name|last_name|display_name|address|city|province)"/', $all))->toBe(0);
});

it('rejects injection attempts in every string argument without running anything', function (string $payload) {
    $count = AiToolsFixture::admin()->table('customers')->count();
    $stringArgs = [
        ['get_sales_trend', 'granularity'], ['get_product_affinity', 'level'], ['get_top_products', 'by'], ['get_customer_metrics_distribution', 'metric'],
        ['get_dashboard_metrics', 'days'], ['get_segment_summary', 'segment_id'], ['get_cohort_report', 'last_cohorts'],
    ];

    foreach ($stringArgs as [$name, $arg]) {
        $r = $this->tools->execute($name, [$arg => $payload]);

        expect($r->failed())->toBeTrue("{$name}.{$arg}")
            ->and($r->result)->toBe([])
            ->and($r->arguments)->toBe([])
            ->and($r->error)->not->toContain(mb_substr($payload, 0, 12))
            ->and(strlen((string) $r->error))->toBeLessThan(300);
    }

    expect(AiToolsFixture::admin()->table('customers')->count())->toBe($count);   // nothing was dropped or touched
})->with([
    'SQL drop' => ["day'; DROP TABLE customers; --"],
    'boolean injection' => ["' OR 1=1 --"],
    'stacked + comment' => ['x"; DELETE FROM segments; /*'],
    'union' => ['1 UNION SELECT phone_normalized FROM customers'],
    'null byte' => ["day\0"],
    'path' => ['../../etc/passwd'],
    'unicode bidi' => ["day\u{202E}"],
    'very long' => [str_repeat('A', 5000)],
]);

it('answers bad calls with a controlled error, never an exception', function (string $name, array $args, string $contains) {
    $r = $this->tools->execute($name, $args);

    expect($r->failed())->toBeTrue()->and($r->error)->toContain($contains)->and($r->result)->toBe([]);
})->with([
    'unknown tool' => ['drop_everything', [], 'Unknown tool'],
    'unknown tool with weird name' => ["x'; --", [], 'Unknown tool'],
    'extra argument' => ['get_rfm_distribution', ['limit' => 5], 'Unknown arguments'],
    'extra argument on a tool with args' => ['get_top_products', ['limit' => 5, 'sort' => 'x'], 'Unknown arguments'],
    'outside enum' => ['get_top_products', ['by' => 'profit'], 'by'],
    'days too small' => ['get_dashboard_metrics', ['days' => 0], 'days'],
    'days too large' => ['get_dashboard_metrics', ['days' => 367], 'days'],
    'days not a number' => ['get_dashboard_metrics', ['days' => 'abc'], 'days'],
    'limit above the row cap' => ['get_top_products', ['limit' => 16], 'limit'],
    'array argument' => ['get_top_products', ['by' => ['revenue']], 'scalars'],
    'day granularity too wide' => ['get_sales_trend', ['days' => 100, 'granularity' => 'day'], 'at most 31'],
]);

it('caps the size of a result and says how to narrow it', function () {
    config(['ai.tools.max_result_bytes' => 100]);

    $r = $this->tools->execute('get_sales_trend', ['days' => 31]);

    expect($r->failed())->toBeTrue()->and($r->error)->toContain('too large')->and($r->result)->toBe([])->and($r->resultSize)->toBeGreaterThan(100);
});

it('reports result size, validated arguments and duration on success', function () {
    $r = $this->tools->execute('get_top_products', ['by' => 'units', 'limit' => '3']);

    expect($r->failed())->toBeFalse()->and($r->name)->toBe('get_top_products')->and($r->arguments)->toBe(['by' => 'units', 'limit' => '3'])
        ->and($r->resultSize)->toBe(strlen(json_encode($r->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->and($r->durationMs)->toBeInt();
});

it('masks small groups exactly at the floor of 5', function () {
    $rows = [['g' => 'a', 'customers' => 5], ['g' => 'b', 'customers' => 4], ['g' => 'c', 'customers' => 1]];

    expect(SmallGroupMask::apply($rows, 'g'))->toBe([['g' => 'a', 'customers' => 5], ['g' => 'other_small_groups', 'customers' => 5, 'merged_groups' => 2]])
        ->and(SmallGroupMask::apply([['g' => 'b', 'customers' => 4], ['g' => 'c', 'customers' => 0]], 'g'))->toBe([['g' => 'other_small_groups', 'customers' => '<5', 'merged_groups' => 2]])
        ->and(SmallGroupMask::count(4))->toBe('<5')->and(SmallGroupMask::count(5))->toBe(5);
});

it('reads only through the ai_readonly role: the same session cannot even see customers', function () {
    $db = DB::connection('ai_readonly');

    expect(fn () => $db->table('customers')->count())->toThrow(QueryException::class, 'permission denied');
});
