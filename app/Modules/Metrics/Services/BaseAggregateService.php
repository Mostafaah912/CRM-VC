<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

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
 * This step only ever writes the columns it owns (identity + raw aggregates). RFM/CLV/churn/lifecycle
 * columns are later pipeline steps (PRD §11 steps 5-10) and are left untouched here. It also never
 * touches `customers.metrics_dirty` — that flag is only cleared once the *whole* pipeline (not just
 * this step) has recomputed a customer, which is P4-07's job.
 */
final class BaseAggregateService
{
    /** Every customer, full recompute. Returns the number of customers processed. */
    public function computeAll(int $metricRunId): int
    {
        return $this->upsert($metricRunId, dirtyOnly: false);
    }

    /** Only customers with `customers.metrics_dirty = true`. Returns the number of customers processed. */
    public function computeDirty(int $metricRunId): int
    {
        return $this->upsert($metricRunId, dirtyOnly: true);
    }

    private function upsert(int $metricRunId, bool $dirtyOnly): int
    {
        // A fixed choice between two literals from config — never a value built from request input.
        $monetaryShippingTerm = config('metrics.include_shipping', false) ? '0' : 'o.shipping_total';
        $dirtyFilter = $dirtyOnly ? 'AND c.metrics_dirty = true' : '';

        return DB::affectingStatement(
            <<<SQL
                INSERT INTO customer_metrics AS cm (
                    customer_id, first_order_at, last_order_at, total_orders, frequency,
                    total_revenue, total_refunded, monetary, aov, recency_days, cohort_month,
                    metric_run_id, computed_at
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
                    CASE
                        WHEN COALESCE(agg.total_orders, 0) = 0 THEN 0
                        ELSE (agg.total_revenue / agg.total_orders)
                    END,
                    CASE
                        WHEN agg.last_order_at IS NULL THEN NULL
                        ELSE GREATEST(0, EXTRACT(EPOCH FROM (NOW() - agg.last_order_at)) / 86400.0)::integer
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
                        SUM(o.net_revenue - {$monetaryShippingTerm})::bigint AS monetary
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
                    aov             = EXCLUDED.aov,
                    recency_days    = EXCLUDED.recency_days,
                    cohort_month    = EXCLUDED.cohort_month,
                    metric_run_id   = EXCLUDED.metric_run_id,
                    computed_at     = EXCLUDED.computed_at
                SQL,
            [$metricRunId],
        );
    }
}
