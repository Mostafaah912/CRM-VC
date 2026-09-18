<?php

declare(strict_types=1);

use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReferenceMetrics;

/*
| tests/fixtures/expected_metrics.json is the contract GATE 2 (Sprint 4) holds the Metrics Engine to.
| It is only trustworthy if it is (a) tied to exactly this demo dataset and (b) confirmed by TWO
| independent implementations: the PHP oracle (tests/Support) and the PRD formulas written as plain
| PostgreSQL below — the language the real engine will use.
*/
function expectedMetrics(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/expected_metrics.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** PRD §11–§14 as one PostgreSQL statement, keyed by phone. Deliberately does not reuse the PHP oracle. */
function sqlReference(): array
{
    $asOf = DemoDataSeeder::asOf()->format('Y-m-d H:i:sP');

    $rows = DB::select("
        with base as (
            select c.id as customer_id, c.phone_normalized as phone, count(*)::int as n,
                   sum(o.net_revenue)::bigint as revenue, sum(o.refunded_total)::bigint as refunded,
                   sum(o.net_revenue - o.shipping_total)::bigint as monetary,
                   min(o.ordered_at) as first_at, max(o.ordered_at) as last_at,
                   floor(extract(epoch from (?::timestamptz - max(o.ordered_at))) / 86400)::int as recency
            from customers c join orders o on o.customer_id = c.id
            where o.is_realized and not o.is_fully_refunded and o.deleted_at is null and c.deleted_at is null and c.status = 'active'
            group by c.id, c.phone_normalized
        ),
        iv as (
            select o.customer_id, o.ordered_at,
                   extract(epoch from (o.ordered_at - lag(o.ordered_at) over (partition by o.customer_id order by o.ordered_at))) / 86400.0 as days
            from orders o where o.is_realized and not o.is_fully_refunded and o.deleted_at is null
        ),
        ivs as (
            select customer_id, percentile_cont(0.5) within group (order by days::double precision) as med, avg(days) as av,
                   (array_agg(days order by ordered_at desc))[1] as last_iv
            from iv where days > 0 and days <= 730 group by customer_id
        ),
        scored as (
            select b.*, ntile(5) over (order by recency desc, customer_id) as r,
                   ntile(5) over (order by n asc, customer_id) as f0,
                   ntile(5) over (order by monetary asc, customer_id) as m
            from base b
        ),
        fx as (
            select s.*, case when s.n <= 4 then least(s.f0, s.n) else s.f0 end as f, i.med, i.av, i.last_iv, coalesce(i.med, 60) as cycle
            from scored s left join ivs i using (customer_id)
        )
        select phone, n, revenue, refunded, monetary, recency, r, f, m, med, round(av, 2)::text as avg_days, cycle,
               r::text || f::text || m::text as rfm_score,
               case when r >= 4 and f >= 4 then 'champion' when r >= 3 and f >= 3 then 'loyal'
                    when r >= 4 and f <= 2 and n > 1 then 'promising' when r = 5 and n = 1 then 'new_customer'
                    when r = 2 and f >= 3 then 'at_risk' when r = 1 and f >= 4 and m >= 4 then 'cant_lose'
                    when r <= 2 and f <= 2 then 'hibernating' when r = 1 then 'lost' else 'promising' end as segment,
               (revenue * 0.17)::bigint as clv_historical,
               case when n < 2 or cycle <= 0 then null else ((revenue / n) * 0.17 * (365.0 / cycle) * 2)::bigint end as clv_estimated,
               case when n < 3 then 'low' when n < 6 then 'medium' else 'high' end as clv_confidence,
               greatest(0, least(100, least(100, (recency / cycle) * 40)
                   + case when n = 1 then 15 else 0 end
                   + case when n >= 3 and last_iv > med * 1.5 then 10 else 0 end
                   + case when m = 5 then -5 else 0 end))::numeric(5,2)::text as churn_score,
               case when recency > 420 then 'lost' when recency > 210 then 'high' when recency > 120 then 'medium' else 'low' end as churn_level,
               case when recency <= 120 then (case when n = 1 then (case when recency <= 60 then 'new' else 'active' end) when n <= 3 then 'repeat' else 'loyal' end)
                    when recency <= 210 then 'at_risk' when recency <= 420 then 'dormant' else 'lost' end as lifecycle,
               to_jalali_month(first_at) as cohort_month
        from fx order by phone
    ", [$asOf]);

    $out = [];
    foreach ($rows as $row) {
        $out[$row->phone] = (array) $row;
    }

    return $out;
}

beforeEach(fn () => app(DemoDataSeeder::class)->run());

it('ships a complete, well-formed fixture for the 50 demo customers', function () {
    $fixture = expectedMetrics();

    expect($fixture['schema_version'])->toBe(1)
        ->and(array_keys($fixture))->toBe(['schema_version', 'meta', 'dataset', 'store', 'customers', 'cohorts'])
        ->and($fixture['customers'])->toHaveCount(50)
        ->and($fixture['meta']['as_of'])->toBe('2026-06-30T08:30:00Z')
        ->and($fixture['meta']['realized_statuses'])->toBe(config('woo.realized_statuses'))
        ->and($fixture['meta']['churn_thresholds'])->toMatchArray(['source' => 'fallback', 'p50' => 60, 'p75' => 120, 'p90' => 210])
        ->and($fixture['meta']['margin_rate'])->toBe('0.17')
        ->and($fixture['meta']['clv_horizon_years'])->toBe(2);

    foreach ($fixture['customers'] as $phone => $customer) {
        expect((string) $phone)->toMatch('/^989\d{9}$/')
            ->and(array_keys($customer))->toBe([
                'total_orders', 'total_revenue', 'total_refunded', 'aov', 'monetary', 'frequency', 'first_order_at', 'last_order_at',
                'recency_days', 'median_days_between', 'avg_days_between', 'purchase_cycle_days', 'expected_next_order_at',
                'r_score', 'f_score', 'm_score', 'rfm_score', 'rfm_segment', 'clv_historical', 'clv_estimated', 'clv_confidence',
                'churn_risk_score', 'churn_risk_level', 'lifecycle_stage', 'cohort_month',
            ]);
    }
});

it('is derived from exactly the current demo dataset (fingerprint) — changing the seeder forces a deliberate fixture review', function () {
    expect(expectedMetrics()['meta']['dataset_fingerprint'])->toBe(ReferenceMetrics::datasetFingerprint());
});

it('matches the independent PHP oracle exactly', function () {
    $computed = ReferenceMetrics::compute(DemoDataSeeder::asOf());
    $expected = expectedMetrics();
    unset($expected['meta']['dataset_fingerprint'], $expected['meta']['scope']);

    expect(json_decode(json_encode($computed), true))->toBe($expected);
});

it('matches the PRD formulas run as plain PostgreSQL SQL: base aggregates, RFM, CLV, churn, lifecycle', function () {
    $sql = sqlReference();
    $customers = expectedMetrics()['customers'];

    expect(array_keys($sql))->toBe(array_keys($customers));

    foreach ($customers as $phone => $expected) {
        $row = $sql[$phone];

        expect([
            'total_orders' => $row['n'], 'total_revenue' => $row['revenue'], 'total_refunded' => $row['refunded'], 'aov' => intdiv($row['revenue'], $row['n']),
            'monetary' => $row['monetary'], 'recency_days' => $row['recency'],
            'r_score' => $row['r'], 'f_score' => $row['f'], 'm_score' => $row['m'], 'rfm_score' => $row['rfm_score'], 'rfm_segment' => $row['segment'],
            'clv_historical' => $row['clv_historical'], 'clv_estimated' => $row['clv_estimated'], 'clv_confidence' => $row['clv_confidence'],
            'churn_risk_score' => $row['churn_score'], 'churn_risk_level' => $row['churn_level'], 'lifecycle_stage' => $row['lifecycle'], 'cohort_month' => $row['cohort_month'],
        ])->toEqual([
            'total_orders' => $expected['total_orders'], 'total_revenue' => $expected['total_revenue'], 'total_refunded' => $expected['total_refunded'], 'aov' => $expected['aov'],
            'monetary' => $expected['monetary'], 'recency_days' => $expected['recency_days'],
            'r_score' => $expected['r_score'], 'f_score' => $expected['f_score'], 'm_score' => $expected['m_score'], 'rfm_score' => $expected['rfm_score'], 'rfm_segment' => $expected['rfm_segment'],
            'clv_historical' => $expected['clv_historical'], 'clv_estimated' => $expected['clv_estimated'], 'clv_confidence' => $expected['clv_confidence'],
            'churn_risk_score' => $expected['churn_risk_score'], 'churn_risk_level' => $expected['churn_risk_level'], 'lifecycle_stage' => $expected['lifecycle_stage'], 'cohort_month' => $expected['cohort_month'],
        ], "customer {$phone}");

        expect((float) $row['cycle'])->toEqual((float) $expected['purchase_cycle_days'], "cycle of {$phone}");
        expect($row['med'] === null ? null : (float) $row['med'])->toEqual($expected['median_days_between'] === null ? null : (float) $expected['median_days_between'], "median of {$phone}")
            ->and($row['avg_days'])->toEqual($expected['avg_days_between'], "average interval of {$phone}");
    }
});

it('matches SQL for the churn threshold sample size, store totals and repeat purchase rate', function () {
    $fixture = expectedMetrics();

    $sample = DB::selectOne('
        select count(*) as n from (
            select extract(epoch from (ordered_at - lag(ordered_at) over (partition by customer_id order by ordered_at))) / 86400.0 as days
            from orders where is_realized and not is_fully_refunded and deleted_at is null
        ) i where days > 0 and days <= 730
    ')->n;

    $store = DB::selectOne('
        select count(*) as orders, sum(total) as gross, sum(refunded_total) as refunded, sum(net_revenue) as net, sum(shipping_total) as shipping
        from orders where is_realized and not is_fully_refunded and deleted_at is null
    ');

    $repeat = DB::selectOne('
        select count(*) filter (where n >= 2) as repeaters, count(*) as buyers, round(count(*) filter (where n >= 2)::numeric / count(*), 4)::text as rate
        from (select customer_id, count(*) as n from orders where is_realized and not is_fully_refunded and deleted_at is null group by customer_id) t
    ');

    expect($fixture['meta']['churn_thresholds']['interval_sample_size'])->toBe((int) $sample)
        ->and((int) $sample)->toBeLessThan(200) // below PRD §14's low-sample guard → fallback 60/120/210 applies
        ->and($fixture['store'])->toMatchArray([
            'counted_orders' => (int) $store->orders, 'gross_revenue' => (int) $store->gross, 'refunded' => (int) $store->refunded,
            'net_revenue' => (int) $store->net, 'shipping' => (int) $store->shipping,
            'customers_with_orders' => (int) $repeat->buyers, 'repeat_customers' => (int) $repeat->repeaters, 'repeat_purchase_rate' => $repeat->rate,
        ]);
});

it('matches SQL for the cohort matrix (PRD §15) using the P0-04 Jalali functions', function () {
    $sqlRows = DB::select('
        with counted as (
            select o.customer_id, o.ordered_at, o.net_revenue from orders o
            where o.is_realized and not o.is_fully_refunded and o.deleted_at is null
        ),
        cohort as (select customer_id, to_jalali_month(min(ordered_at)) as cohort_month from counted group by customer_id),
        size as (select cohort_month, count(*) as cohort_size from cohort group by cohort_month),
        cells as (
            select ch.cohort_month, jalali_month_diff(ch.cohort_month, to_jalali_month(c.ordered_at)) as period,
                   count(distinct c.customer_id) as active, count(*) as orders_count, sum(c.net_revenue) as revenue
            from counted c join cohort ch using (customer_id) group by 1, 2
        )
        select cells.cohort_month, cells.period::int, size.cohort_size::int, cells.active::int, cells.orders_count::int, cells.revenue::bigint,
               sum(cells.revenue) over (partition by cells.cohort_month order by cells.period)::bigint as cumulative,
               round(cells.active::numeric / size.cohort_size, 4)::text as rate,
               jalali_month_diff(cells.cohort_month, to_jalali_month(?::timestamptz)) >= cells.period as mature
        from cells join size using (cohort_month) order by 1, 2
    ', [DemoDataSeeder::asOf()->format('Y-m-d H:i:sP')]);

    $sql = array_map(fn ($r) => [
        'cohort_month' => $r->cohort_month, 'period_number' => $r->period, 'cohort_size' => $r->cohort_size, 'active_customers' => $r->active,
        'retention_rate' => $r->rate, 'orders_count' => $r->orders_count, 'revenue' => $r->revenue, 'cumulative_revenue' => $r->cumulative, 'is_mature' => $r->mature,
    ], $sqlRows);

    expect(expectedMetrics()['cohorts'])->toBe($sql)->and($sql)->not->toBeEmpty();
});

it('locks in the three PRD silent-bug rules on the seeded data (E1/E2/E3)', function () {
    $customers = expectedMetrics()['customers'];
    $byRecency = collect($customers)->sortBy('recency_days');

    // E2: the most recent customer must score r=5, the least recent r=1.
    expect($byRecency->first()['r_score'])->toBe(5)->and($byRecency->last()['r_score'])->toBe(1);

    // E1: the highest monetary customer scores m=5, the lowest m=1.
    $byMonetary = collect($customers)->sortBy('monetary');
    expect($byMonetary->last()['m_score'])->toBe(5)->and($byMonetary->first()['m_score'])->toBe(1);

    // E3: nobody with <= 4 orders may score f above their order count — a one-order customer is f=1.
    foreach ($customers as $customer) {
        if ($customer['frequency'] <= 4) {
            expect($customer['f_score'])->toBeLessThanOrEqual($customer['frequency']);
        }
    }
    expect(collect($customers)->where('frequency', 1)->pluck('f_score')->unique()->all())->toBe([1]);
});

it('honours the nullability rules: CLV is NULL below 2 orders, and every customer is scored', function () {
    foreach (expectedMetrics()['customers'] as $customer) {
        expect($customer['r_score'])->toBeBetween(1, 5)->and($customer['f_score'])->toBeBetween(1, 5)->and($customer['m_score'])->toBeBetween(1, 5);

        if ($customer['total_orders'] < 2) {
            expect($customer['clv_estimated'])->toBeNull()->and($customer['median_days_between'])->toBeNull();
        } else {
            expect($customer['clv_estimated'])->toBeInt()->toBeGreaterThan(0)->and($customer['median_days_between'])->not->toBeNull();
        }
    }
});

it('covers enough variety to exercise the later Metrics tests', function () {
    $store = expectedMetrics()['store'];

    expect(count($store['rfm_segment_counts']))->toBeGreaterThanOrEqual(6)
        ->and(array_keys($store['churn_level_counts']))->toEqualCanonicalizing(['low', 'medium', 'high', 'lost'])
        ->and(array_keys($store['lifecycle_counts']))->toEqualCanonicalizing(['new', 'active', 'repeat', 'loyal', 'at_risk', 'dormant', 'lost'])
        ->and(array_keys(expectedMetrics()['dataset']['orders_by_status']))->toEqualCanonicalizing(['cancelled', 'completed', 'failed', 'on-hold', 'pending', 'processing'])
        ->and(count(expectedMetrics()['cohorts']))->toBeGreaterThan(20);
});
