<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRD §11 step 3: the base aggregates, one UPSERT, never a PHP loop over customers (CLAUDE.md §3).
 * Reads `orders` and `customers` with the query builder / raw SQL only — the Metrics module is the
 * documented Rule 7 exception (ArchitectureTest) precisely for aggregates like this one.
 *
 * Only realized, not-fully-refunded, non-deleted orders count. A customer with none gets a row of
 * zeros and a NULL recency_days — "no orders" is a fact, not a missing value, but there is nothing
 * to count days since. `monetary` is `net_revenue` minus shipping unless `metrics.include_shipping`
 * says otherwise (PRD D2); the choice is a fixed, config-selected SQL literal, never request input.
 *
 * `monetary_recent` (P6-20, product-owner decision): the same sum, filtered to realized orders
 * within `metrics.monetary.window_days` of `$asOf` — always computed, regardless of
 * `metrics.monetary.mode`, so RfmCalculator can score it the moment an operator flips the mode, with
 * no separate backfill step. NULL (via `FILTER`, never `COALESCE`d to 0) means "no realized purchase
 * in the window" — a real signal RfmCalculator reads directly (not the same thing as "spent 0"). The
 * window boundary is a Tehran CALENDAR day, not a raw `$asOf - N*86400s` subtraction: `$asOf` is
 * converted to Asia/Tehran, walked back `window_days` days, then floored to that day's own midnight —
 * the same "Tehran local day" `DailyMetricsService` (P6-02) already established for calendar-day
 * grouping, picked here over `JalaliDay::start()` because the input is an already-known instant, not
 * a Jalali date string to parse.
 *
 * This step only ever writes the columns it owns (identity + raw aggregates). RFM/CLV/churn/lifecycle
 * columns are later pipeline steps (PRD §11 steps 5-10) and are left untouched here. `upsert()` itself
 * never touches `customers.metrics_dirty` — that flag is only cleared once the *whole* pipeline (not
 * just this step) has recomputed a customer, via {@see resetDirtyFlag()} (PRD §11 step 11), called by
 * `MetricsRecomputeService::run()` after every later step.
 *
 * `$asOf` (P4-08, Gate 2): `recency_days` is bound to this instant, never Postgres's own `NOW()` —
 * a literal NOW() can never be reproduced by a test asserting against a fixture frozen at a fixed
 * historical moment (tests/fixtures/expected_metrics.json is anchored at DemoDataSeeder::AS_OF).
 * Defaults to the real current time, so production behavior is unchanged; `computed_at` always stays
 * the true wall-clock instant this row was actually written, regardless of `$asOf`.
 */
final class BaseAggregateService
{
    /** Every customer, full recompute. Returns the number of customers processed. */
    public function computeAll(int $metricRunId, ?CarbonImmutable $asOf = null): int
    {
        return $this->upsert($metricRunId, dirtyOnly: false, asOf: $asOf ?? CarbonImmutable::now());
    }

    /** Only customers with `customers.metrics_dirty = true`. Returns the number of customers processed. */
    public function computeDirty(int $metricRunId, ?CarbonImmutable $asOf = null): int
    {
        return $this->upsert($metricRunId, dirtyOnly: true, asOf: $asOf ?? CarbonImmutable::now());
    }

    /**
     * PRD §11 step 11. Clears `metrics_dirty` for exactly the customers this run actually wrote a
     * `customer_metrics` row for (identified by `metric_run_id`, set by {@see upsert()} above) —
     * never "every dirty customer", because a dirty run only upserted the ones that were dirty, and a
     * full run upserted everyone, so `metric_run_id = $metricRunId` already means "processed by this
     * run" for both modes without tracking anything new. A customer excluded from the upsert entirely
     * (soft-deleted) keeps whatever `metrics_dirty` value it already had.
     */
    public function resetDirtyFlag(int $metricRunId): int
    {
        return DB::affectingStatement(
            <<<'SQL'
                UPDATE customers c SET metrics_dirty = false
                FROM customer_metrics cm
                WHERE cm.customer_id = c.id
                  AND cm.metric_run_id = ?
                  AND c.metrics_dirty = true
                SQL,
            [$metricRunId],
        );
    }

    private function monetaryWindowStart(CarbonImmutable $asOf): CarbonImmutable
    {
        $windowDays = (int) config('metrics.monetary.window_days', 60);

        return $asOf->setTimezone('Asia/Tehran')->subDays($windowDays)->startOfDay();
    }

    private function upsert(int $metricRunId, bool $dirtyOnly, CarbonImmutable $asOf): int
    {
        // A fixed choice between two literals from config — never a value built from request input.
        $monetaryShippingTerm = config('metrics.include_shipping', false) ? '0' : 'o.shipping_total';
        $dirtyFilter = $dirtyOnly ? 'AND c.metrics_dirty = true' : '';
        $windowStart = $this->monetaryWindowStart($asOf)->format('Y-m-d H:i:sP');

        return DB::affectingStatement(
            <<<SQL
                INSERT INTO customer_metrics AS cm (
                    customer_id, first_order_at, last_order_at, total_orders, frequency,
                    total_revenue, total_refunded, monetary, monetary_recent, aov, recency_days,
                    cohort_month, metric_run_id, computed_at
                )
                SELECT
                    c.id,
                    agg.first_order_at,
                    agg.last_order_at,
                    COALESCE(agg.total_orders, 0),
                    COALESCE(agg.total_orders, 0),
                    COALESCE(agg.total_revenue, 0),
                    COALESCE(agg.total_refunded, 0),
                    COALESCE(agg.monetary, 0),
                    agg.monetary_recent,
                    CASE
                        WHEN COALESCE(agg.total_orders, 0) = 0 THEN 0
                        ELSE (agg.total_revenue / agg.total_orders)
                    END,
                    CASE
                        WHEN agg.last_order_at IS NULL THEN NULL
                        ELSE GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - agg.last_order_at)) / 86400.0)::integer
                    END,
                    to_jalali_month(agg.first_order_at),
                    ?,
                    NOW()
                FROM customers c
                LEFT JOIN LATERAL (
                    SELECT
                        MIN(o.ordered_at) AS first_order_at,
                        MAX(o.ordered_at) AS last_order_at,
                        COUNT(*)::integer AS total_orders,
                        SUM(o.net_revenue)::bigint AS total_revenue,
                        SUM(o.refunded_total)::bigint AS total_refunded,
                        SUM(o.net_revenue - {$monetaryShippingTerm})::bigint AS monetary,
                        SUM(o.net_revenue - {$monetaryShippingTerm}) FILTER (WHERE o.ordered_at >= ?::timestamptz)::bigint AS monetary_recent
                    FROM orders o
                    WHERE o.customer_id = c.id
                      AND o.is_realized = true
                      AND o.is_fully_refunded = false
                      AND o.deleted_at IS NULL
                ) agg ON true
                WHERE c.deleted_at IS NULL {$dirtyFilter}
                ON CONFLICT (customer_id) DO UPDATE SET
                    first_order_at  = EXCLUDED.first_order_at,
                    last_order_at   = EXCLUDED.last_order_at,
                    total_orders    = EXCLUDED.total_orders,
                    frequency       = EXCLUDED.frequency,
                    total_revenue   = EXCLUDED.total_revenue,
                    total_refunded  = EXCLUDED.total_refunded,
                    monetary        = EXCLUDED.monetary,
                    monetary_recent = EXCLUDED.monetary_recent,
                    aov             = EXCLUDED.aov,
                    recency_days    = EXCLUDED.recency_days,
                    cohort_month    = EXCLUDED.cohort_month,
                    metric_run_id   = EXCLUDED.metric_run_id,
                    computed_at     = EXCLUDED.computed_at
                SQL,
            [$asOf->format('Y-m-d H:i:sP'), $metricRunId, $windowStart],
        );
    }
}
