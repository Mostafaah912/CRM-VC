<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\DailyMetricsSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRD §9's `daily_metrics` rollup — one INSERT...SELECT over a `generate_series` of calendar days,
 * never a PHP loop over orders (CLAUDE.md §3). Reads `orders` and `customer_metrics` with the query
 * builder / raw SQL only; the Analytics module is a documented, blanket Rule 7 exception (same as
 * `CustomerPurchaseAggregateService`, P6-01) — no arch-test change needed.
 *
 * Unlike P6-01's purchase aggregates (a full TRUNCATE-and-rebuild every run), this UPSERTs only the
 * requested trailing window (`ON CONFLICT (date) DO UPDATE`) and leaves every older day untouched —
 * PRD §22's nightly chain calls `BuildDailyMetricsJob(3)`, a 3-day correction window (today plus the
 * two days before it), not a full-history rebuild. PRD doesn't spell out what the "(3)" argument means;
 * this is the simplest interpretation consistent with the rest of the pipeline's "dirty window" pattern
 * (PRD §11's `metrics:recompute --dirty`): recent days can still change (late refunds, late-synced
 * orders), older days are stable once their window has passed, so only the tail needs re-touching.
 * A day with zero orders still gets a written row of zeros — "no orders" is a fact, not a missing
 * value, the same rule `BaseAggregateService` already applies per customer.
 *
 * "Realized order" here means exactly what `BaseAggregateService` (PRD §11) means: `is_realized = true
 * AND is_fully_refunded = false AND deleted_at IS NULL` — one definition of "counted order" for the
 * whole Metrics/Analytics surface, not a second one specific to this table. This is a different filter
 * than P6-01's purchase aggregates (PRD §16 has no `is_fully_refunded` filter there) — that difference
 * was already PRD-specified and documented in P6-01; this one is a fresh, deliberate choice to reuse
 * Base Aggregates' definition since `daily_metrics` feeds the same Dashboard trend numbers.
 *
 * A calendar "day" is the Tehran-local Gregorian date (`ordered_at AT TIME ZONE 'Asia/Tehran'`), not
 * UTC — CLAUDE.md §2: timestamps are stored UTC, every Jalali/day-boundary display or grouping goes
 * through Asia/Tehran first. `jalali_date` is written with the same PL/pgSQL `to_jalali()` function
 * `BaseAggregateService` already uses for `cohort_month`, so both stay derived from one algorithm.
 *
 * `customers_new`/`customers_repeat`/`revenue_new`/`revenue_repeat` classify each customer by comparing
 * this day against `customer_metrics.first_order_at` (also Tehran-local) — not recomputed from `orders`
 * a second time, reusing the one place this fact already lives. This makes the job's PRD §22 chain
 * position load-bearing: it must run after `RecomputeMetricsJob('full')` (as the chain already
 * specifies) so `first_order_at` is fresh; a customer whose `customer_metrics` row doesn't exist yet
 * falls back to "repeat" here rather than "new" until the next full recompute catches them up.
 */
final class DailyMetricsService
{
    /** `$asOf` (Gate-2-style determinism, see BaseAggregateService): defaults to the real current instant. */
    public function rebuild(int $days, ?CarbonImmutable $asOf = null): DailyMetricsSummary
    {
        $start = microtime(true);

        $end = ($asOf ?? CarbonImmutable::now())->setTimezone('Asia/Tehran')->toDateString();
        $from = CarbonImmutable::parse($end)->subDays($days - 1)->toDateString();

        DB::affectingStatement(<<<'SQL'
            WITH days AS (
                SELECT generate_series(?::date, ?::date, interval '1 day')::date AS d
            ),
            day_orders AS (
                SELECT
                    o.id AS order_id,
                    o.customer_id,
                    o.total,
                    o.refunded_total,
                    o.net_revenue,
                    (o.ordered_at AT TIME ZONE 'Asia/Tehran')::date AS order_day,
                    (cm.first_order_at AT TIME ZONE 'Asia/Tehran')::date AS first_order_day
                FROM orders o
                LEFT JOIN customer_metrics cm ON cm.customer_id = o.customer_id
                WHERE o.is_realized = true
                  AND o.is_fully_refunded = false
                  AND o.deleted_at IS NULL
                  AND (o.ordered_at AT TIME ZONE 'Asia/Tehran')::date BETWEEN ? AND ?
            )
            INSERT INTO daily_metrics (
                date, jalali_date, orders_count, revenue, refunds, net_revenue, aov,
                customers_total, customers_new, customers_repeat, revenue_new, revenue_repeat, computed_at
            )
            SELECT
                days.d,
                to_jalali(days.d::timestamptz),
                COALESCE(COUNT(day_orders.order_id), 0)::integer,
                COALESCE(SUM(day_orders.total), 0)::bigint,
                COALESCE(SUM(day_orders.refunded_total), 0)::bigint,
                COALESCE(SUM(day_orders.net_revenue), 0)::bigint,
                COALESCE(SUM(day_orders.net_revenue) / NULLIF(COUNT(day_orders.order_id), 0), 0)::bigint,
                COALESCE(COUNT(DISTINCT day_orders.customer_id), 0)::integer,
                COALESCE(COUNT(DISTINCT CASE WHEN day_orders.first_order_day = days.d THEN day_orders.customer_id END), 0)::integer,
                COALESCE(COUNT(DISTINCT day_orders.customer_id), 0)::integer
                    - COALESCE(COUNT(DISTINCT CASE WHEN day_orders.first_order_day = days.d THEN day_orders.customer_id END), 0)::integer,
                COALESCE(SUM(CASE WHEN day_orders.first_order_day = days.d THEN day_orders.total ELSE 0 END), 0)::bigint,
                COALESCE(SUM(day_orders.total), 0)::bigint
                    - COALESCE(SUM(CASE WHEN day_orders.first_order_day = days.d THEN day_orders.total ELSE 0 END), 0)::bigint,
                NOW()
            FROM days
            LEFT JOIN day_orders ON day_orders.order_day = days.d
            GROUP BY days.d
            ON CONFLICT (date) DO UPDATE SET
                jalali_date      = EXCLUDED.jalali_date,
                orders_count     = EXCLUDED.orders_count,
                revenue          = EXCLUDED.revenue,
                refunds          = EXCLUDED.refunds,
                net_revenue      = EXCLUDED.net_revenue,
                aov              = EXCLUDED.aov,
                customers_total  = EXCLUDED.customers_total,
                customers_new    = EXCLUDED.customers_new,
                customers_repeat = EXCLUDED.customers_repeat,
                revenue_new      = EXCLUDED.revenue_new,
                revenue_repeat   = EXCLUDED.revenue_repeat,
                computed_at      = EXCLUDED.computed_at
            SQL,
            [$from, $end, $from, $end],
        );

        return new DailyMetricsSummary(
            daysProcessed: $days,
            elapsedMs: (int) round((microtime(true) - $start) * 1000),
        );
    }
}
