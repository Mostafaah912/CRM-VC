<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TEST ORACLE — an independent, deliberately naive PHP implementation of PRD §11–§15 over the demo data.
 * It exists ONLY to derive and verify tests/fixtures/expected_metrics.json. The production Metrics Engine
 * (Sprint 4) must be SQL, never a PHP loop (CLAUDE.md §1); the two implementations agreeing is the point
 * of GATE 2.
 *
 * Exact arithmetic on purpose: every quantity is an integer or a rational (numerator/denominator), rounded
 * half-up exactly where PostgreSQL rounds on cast/assignment, and it THROWS if a value lands on a rounding
 * tie, so no fixture number depends on a rounding convention the PRD does not define.
 */
final class ReferenceMetrics
{
    private const FALLBACK = ['p50' => 60, 'p75' => 120, 'p90' => 210];

    private const MIN_INTERVAL_SAMPLE = 200;

    /** @var list<string> rounding ties found during one compute() — all reported together */
    private static array $ties = [];

    /** @return array<string, mixed> */
    public static function compute(CarbonImmutable $asOf): array
    {
        self::$ties = [];
        $realized = config('woo.realized_statuses');

        $customers = DB::table('customers')->whereNull('deleted_at')->where('status', 'active')->orderBy('id')->get();
        $counted = DB::table('orders')->where('is_realized', true)->where('is_fully_refunded', false)->whereNull('deleted_at')
            ->orderBy('ordered_at')->orderBy('id')->get()->groupBy('customer_id');

        $base = [];
        $intervalCount = 0;

        foreach ($customers as $customer) {
            $orders = $counted->get($customer->id, collect());
            if ($orders->isEmpty()) {
                continue;
            }

            $times = $orders->map(fn ($o) => CarbonImmutable::parse($o->ordered_at)->getTimestamp())->all();
            $intervals = [];
            for ($i = 1; $i < count($times); $i++) {
                $seconds = $times[$i] - $times[$i - 1];
                self::whole($seconds, 'order interval');
                $days = intdiv($seconds, 86400);
                if ($days > 0 && $days <= 730) {
                    $intervals[] = $days;
                }
            }
            $intervalCount += count($intervals);

            $n = $orders->count();
            $revenue = (int) $orders->sum('net_revenue');
            if ($revenue % $n !== 0) {
                throw new RuntimeException("customer {$customer->phone_normalized}: revenue {$revenue} not divisible by {$n} orders (AOV would need a rounding rule)");
            }
            if ($revenue % 100 !== 0) {
                throw new RuntimeException('revenue not divisible by 100 (CLV margin would need a rounding rule)');
            }

            $recencySeconds = $asOf->getTimestamp() - end($times);
            self::whole($recencySeconds, 'recency');

            $base[$customer->id] = [
                'phone' => $customer->phone_normalized,
                'n' => $n,
                'revenue' => $revenue,
                'refunded' => (int) $orders->sum('refunded_total'),
                'monetary' => (int) $orders->sum(fn ($o) => $o->net_revenue - $o->shipping_total),
                'first' => CarbonImmutable::parse($orders->first()->ordered_at),
                'last' => CarbonImmutable::parse($orders->last()->ordered_at),
                'recency' => intdiv($recencySeconds, 86400),
                'intervals' => $intervals,
            ];
        }

        if ($intervalCount >= self::MIN_INTERVAL_SAMPLE) {
            throw new RuntimeException('oracle only models the low-sample fallback thresholds (PRD §14); the demo data must stay below 200 intervals');
        }
        $t = self::FALLBACK;

        $scores = self::rfm($base);
        $rows = [];

        foreach ($base as $id => $b) {
            $n = $b['n'];
            $aov = intdiv($b['revenue'], $n);
            $cycle2 = self::medianTwice($b['intervals']) ?? $t['p50'] * 2;
            $hasMedian = $b['intervals'] !== [];
            [$r, $f, $m] = $scores[$id];

            $lastInterval = $b['intervals'] === [] ? null : end($b['intervals']);
            $score = self::churnScore($b['recency'], $cycle2, $n, $lastInterval, $hasMedian, $m);

            $rows[$b['phone']] = [
                'total_orders' => $n,
                'total_revenue' => $b['revenue'],
                'total_refunded' => $b['refunded'],
                'aov' => $aov,
                'monetary' => $b['monetary'],
                'frequency' => $n,
                'first_order_at' => $b['first']->utc()->format('Y-m-d\TH:i:s\Z'),
                'last_order_at' => $b['last']->utc()->format('Y-m-d\TH:i:s\Z'),
                'recency_days' => $b['recency'],
                'median_days_between' => $hasMedian ? self::twoDecimals($cycle2 * 50) : null,
                'avg_days_between' => $hasMedian ? self::twoDecimals(self::roundDiv(array_sum($b['intervals']) * 200, 2 * count($b['intervals']))) : null,
                'purchase_cycle_days' => self::twoDecimals($cycle2 * 50),
                'expected_next_order_at' => $b['last']->addSeconds($cycle2 * 43200)->utc()->format('Y-m-d\TH:i:s\Z'),
                'r_score' => $r,
                'f_score' => $f,
                'm_score' => $m,
                'rfm_score' => "{$r}{$f}{$m}",
                'rfm_segment' => self::segment($r, $f, $m, $n),
                'clv_historical' => intdiv($b['revenue'] * 17, 100),
                'clv_estimated' => $n < 2 ? null : self::clvEstimated($aov, $cycle2),
                'clv_confidence' => $n < 3 ? 'low' : ($n < 6 ? 'medium' : 'high'),
                'churn_risk_score' => $score,
                'churn_risk_level' => $b['recency'] > $t['p90'] * 2 ? 'lost' : ($b['recency'] > $t['p90'] ? 'high' : ($b['recency'] > $t['p75'] ? 'medium' : 'low')),
                'lifecycle_stage' => self::lifecycle($n, $b['recency'], $t),
                'cohort_month' => JalaliDate::toJalaliMonth($b['first']),
            ];
        }
        ksort($rows);

        $result = [
            'schema_version' => 1,
            'meta' => [
                'as_of' => $asOf->utc()->format('Y-m-d\TH:i:s\Z'),
                'as_of_jalali_month' => JalaliDate::toJalaliMonth($asOf),
                'definition_version' => 'v1',
                'margin_rate' => '0.17',
                'clv_horizon_years' => 2,
                'realized_statuses' => $realized,
                'churn_thresholds' => ['source' => 'fallback', 'interval_sample_size' => $intervalCount] + $t,
            ],
            'dataset' => self::dataset(),
            'store' => self::store($rows),
            'customers' => $rows,
            'cohorts' => self::cohorts($base, $asOf),
        ];

        if (self::$ties !== []) {
            throw new RuntimeException("rounding ties (adjust the demo data instead of relying on a rounding convention):\n".implode("\n", self::$ties));
        }

        return $result;
    }

    /** Business-key fingerprint (ids excluded) so the fixture can prove which dataset it was derived from. */
    public static function datasetFingerprint(): string
    {
        $parts = [
            "select string_agg(concat_ws('~', phone_normalized, first_name, last_name, province, city, first_seen_at), '|' order by phone_normalized) from customers",
            "select string_agg(concat_ws('~', o.woo_order_id, c.phone_normalized, o.status, o.is_realized, o.total, o.subtotal, o.discount_total, o.shipping_total, o.tax_total, o.refunded_total, o.is_fully_refunded, o.ordered_at, coalesce(o.deleted_at::text, '')), '|' order by o.woo_order_id) from orders o join customers c on c.id = o.customer_id",
            "select string_agg(concat_ws('~', i.woo_item_id, o.woo_order_id, coalesce(i.sku, ''), i.name_snapshot, i.qty, i.unit_price, i.line_total, i.refunded_qty, i.refunded_amount), '|' order by i.woo_item_id) from order_items i join orders o on o.id = i.order_id",
            "select string_agg(concat_ws('~', r.woo_refund_id, o.woo_order_id, r.amount, r.is_full), '|' order by r.woo_refund_id) from refunds r join orders o on o.id = r.order_id",
        ];

        return md5(implode('#', array_map(fn (string $sql) => (string) (array_values((array) DB::selectOne($sql))[0] ?? ''), $parts)));
    }

    /** @return array<int, array{0: int, 1: int, 2: int}> customer id => [r, f, m] after the PRD §12 frequency correction */
    private static function rfm(array $base): array
    {
        $r = self::ntile(self::sortedIds($base, fn ($x) => -$x['recency']));
        $f = self::ntile(self::sortedIds($base, fn ($x) => $x['n']));
        $m = self::ntile(self::sortedIds($base, fn ($x) => $x['monetary']));

        $out = [];
        foreach ($base as $id => $b) {
            $frequency = $f[$id];
            if ($b['n'] <= 4) {
                $frequency = min($frequency, $b['n']); // PRD E3
            }
            $out[$id] = [$r[$id], $frequency, $m[$id]];
        }

        return $out;
    }

    /** @return list<int> customer ids ordered by (key ASC, id ASC) — the ORDER BY of each NTILE window */
    private static function sortedIds(array $base, callable $key): array
    {
        $ids = array_keys($base);
        usort($ids, fn (int $a, int $b) => [$key($base[$a]), $a] <=> [$key($base[$b]), $b]);

        return $ids;
    }

    /**
     * PostgreSQL NTILE(5): the first (N mod 5) buckets get one extra row.
     *
     * @param  list<int>  $orderedIds
     * @return array<int, int> id => bucket 1..5
     */
    private static function ntile(array $orderedIds): array
    {
        $total = count($orderedIds);
        [$size, $extra] = [intdiv($total, 5), $total % 5];
        $out = [];
        $position = 0;

        for ($bucket = 1; $bucket <= 5; $bucket++) {
            for ($i = 0; $i < $size + ($bucket <= $extra ? 1 : 0); $i++) {
                $out[$orderedIds[$position++]] = $bucket;
            }
        }

        return $out;
    }

    /** PRD §12 CASE — order matters (cant_lose before lost). */
    private static function segment(int $r, int $f, int $m, int $frequency): string
    {
        return match (true) {
            $r >= 4 && $f >= 4 => 'champion',
            $r >= 3 && $f >= 3 => 'loyal',
            $r >= 4 && $f <= 2 && $frequency > 1 => 'promising',
            $r === 5 && $frequency === 1 => 'new_customer',
            $r === 2 && $f >= 3 => 'at_risk',
            $r === 1 && $f >= 4 && $m >= 4 => 'cant_lose',
            $r <= 2 && $f <= 2 => 'hibernating',
            $r === 1 => 'lost',
            default => 'promising',
        };
    }

    /** PRD §11 lifecycle_stage. @param  array{p50: int, p75: int, p90: int}  $t */
    private static function lifecycle(int $n, int $recency, array $t): string
    {
        return match (true) {
            $recency <= $t['p75'] => $n === 1 ? ($recency <= $t['p50'] ? 'new' : 'active') : ($n <= 3 ? 'repeat' : 'loyal'),
            $recency <= $t['p90'] => 'at_risk',
            $recency <= $t['p90'] * 2 => 'dormant',
            default => 'lost',
        };
    }

    /** @param  list<int>  $intervals  @return int|null median × 2 (medians of whole days are whole or half days) */
    private static function medianTwice(array $intervals): ?int
    {
        if ($intervals === []) {
            return null;
        }
        sort($intervals);
        $count = count($intervals);

        return $count % 2 === 1 ? 2 * $intervals[intdiv($count, 2)] : $intervals[$count / 2 - 1] + $intervals[$count / 2];
    }

    /** (aov × 0.17 × (365 / cycle) × 2)::bigint with cycle = cycle2 / 2 — exact rational, half-up. */
    private static function clvEstimated(int $aov, int $cycle2): int
    {
        $numerator = $aov * 17 * 365 * 4;
        $denominator = 100 * $cycle2;

        return self::roundDiv($numerator, $denominator);
    }

    /** PRD §14 step 3, numeric(5,2), exact. @return string like "83.33" */
    private static function churnScore(int $recency, int $cycle2, int $n, ?int $lastInterval, bool $hasMedian, int $m): string
    {
        [$num, $den] = [80 * $recency, $cycle2];      // ratio × 40 with ratio = recency / (cycle2 / 2)
        if ($num >= 100 * $den) {
            [$num, $den] = [100, 1];                  // LEAST(100, base)
        }

        $adjust = ($n === 1 ? 15 : 0)
            + ($n >= 3 && $hasMedian && $lastInterval !== null && $lastInterval * 4 > 3 * $cycle2 ? 10 : 0)
            + ($m === 5 ? -5 : 0);

        $num += $adjust * $den;
        $num = max(0, min($num, 100 * $den));          // GREATEST(0, LEAST(100, …))

        return self::twoDecimals(self::roundDiv($num * 100, $den));
    }

    /** Round n/d half-up to an integer; records an exact .5 tie so compute() can refuse it (no fixture value may depend on a rounding rule). */
    private static function roundDiv(int $n, int $d): int
    {
        if ((2 * $n) % (2 * $d) === $d) {
            self::$ties[] = "{$n}/{$d}";
        }

        return intdiv(2 * $n + $d, 2 * $d);
    }

    private static function twoDecimals(int $hundredths): string
    {
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private static function whole(int $seconds, string $what): void
    {
        if ($seconds % 86400 !== 0) {
            throw new RuntimeException("{$what} is not a whole number of days — the fixture assumes whole-day intervals");
        }
    }

    /** @return array<string, mixed> */
    private static function dataset(): array
    {
        $byStatus = DB::table('orders')->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return [
            'customers' => DB::table('customers')->count(),
            'products' => DB::table('products')->count(),
            'variations' => DB::table('product_variations')->count(),
            'orders' => DB::table('orders')->count(),
            'orders_by_status' => $byStatus,
            'soft_deleted_orders' => DB::table('orders')->whereNotNull('deleted_at')->count(),
            'fully_refunded_orders' => DB::table('orders')->where('is_fully_refunded', true)->count(),
            'order_items' => DB::table('order_items')->count(),
            'refunds' => DB::table('refunds')->count(),
            'customers_with_refunds' => DB::table('orders')->join('refunds', 'refunds.order_id', '=', 'orders.id')->distinct()->count('orders.customer_id'),
        ];
    }

    /** @param  array<string, array<string, mixed>>  $rows @return array<string, mixed> */
    private static function store(array $rows): array
    {
        $buyers = count($rows);
        $repeaters = count(array_filter($rows, fn ($r) => $r['total_orders'] >= 2));
        $rate = self::roundDiv($repeaters * 10000, $buyers);
        $counted = DB::table('orders')->where('is_realized', true)->where('is_fully_refunded', false)->whereNull('deleted_at');
        $count = function (string $column) use ($rows): array {
            $counts = array_count_values(array_column($rows, $column));
            ksort($counts);

            return $counts;
        };

        return [
            'counted_orders' => (clone $counted)->count(),
            'gross_revenue' => (int) (clone $counted)->sum('total'),
            'refunded' => (int) (clone $counted)->sum('refunded_total'),
            'net_revenue' => (int) (clone $counted)->sum('net_revenue'),
            'shipping' => (int) (clone $counted)->sum('shipping_total'),
            'customers_with_orders' => $buyers,
            'repeat_customers' => $repeaters,
            'repeat_purchase_rate' => sprintf('%d.%04d', intdiv($rate, 10000), $rate % 10000),
            'rfm_segment_counts' => $count('rfm_segment'),
            'lifecycle_counts' => $count('lifecycle_stage'),
            'churn_level_counts' => $count('churn_risk_level'),
        ];
    }

    /** @param  array<int, array<string, mixed>>  $base @return list<array<string, mixed>> cohort rows that have activity */
    private static function cohorts(array $base, CarbonImmutable $asOf): array
    {
        $cohortOf = [];
        $size = [];
        foreach ($base as $id => $b) {
            $cohortOf[$id] = JalaliDate::toJalaliMonth($b['first']);
            $size[$cohortOf[$id]] = ($size[$cohortOf[$id]] ?? 0) + 1;
        }

        $cells = [];
        $orders = DB::table('orders')->where('is_realized', true)->where('is_fully_refunded', false)->whereNull('deleted_at')->get();
        foreach ($orders as $o) {
            if (! isset($cohortOf[$o->customer_id])) {
                continue;
            }
            $cohort = $cohortOf[$o->customer_id];
            $period = self::monthIndex(JalaliDate::toJalaliMonth(CarbonImmutable::parse($o->ordered_at))) - self::monthIndex($cohort);
            $cell = &$cells[$cohort][$period];
            $cell ??= ['customers' => [], 'orders' => 0, 'revenue' => 0];
            $cell['customers'][$o->customer_id] = true;
            $cell['orders']++;
            $cell['revenue'] += $o->net_revenue;
            unset($cell);
        }

        ksort($cells);
        $asOfMonth = self::monthIndex(JalaliDate::toJalaliMonth($asOf));
        $rows = [];

        foreach ($cells as $cohort => $periods) {
            ksort($periods);
            $cumulative = 0;
            foreach ($periods as $period => $cell) {
                $cumulative += $cell['revenue'];
                $active = count($cell['customers']);
                $rate = self::roundDiv($active * 10000, $size[$cohort]);
                $rows[] = [
                    'cohort_month' => $cohort,
                    'period_number' => $period,
                    'cohort_size' => $size[$cohort],
                    'active_customers' => $active,
                    'retention_rate' => sprintf('%d.%04d', intdiv($rate, 10000), $rate % 10000),
                    'orders_count' => $cell['orders'],
                    'revenue' => $cell['revenue'],
                    'cumulative_revenue' => $cumulative,
                    'is_mature' => $asOfMonth - self::monthIndex($cohort) >= $period,
                ];
            }
        }

        return $rows;
    }

    private static function monthIndex(string $jalaliMonth): int
    {
        [$year, $month] = array_map('intval', explode('-', $jalaliMonth));

        return $year * 12 + $month;
    }
}
