<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use Illuminate\Support\Facades\DB;

/**
 * PRD §14 step 2: `purchase_cycle_days = COALESCE(personal_median_days_between, store_p50)`. Median,
 * not mean, so one abnormally long or short gap never drags a customer's whole cycle with it. A
 * customer with a single realized order has no interval to measure — PERCENTILE_CONT's FILTER
 * clause is empty for them, `personal_median` comes back NULL, and COALESCE falls through to the
 * store's own p50 (their intervals are all still to be discovered).
 *
 * The window function (LAG) and the aggregate (PERCENTILE_CONT) cannot appear in the same SELECT —
 * Postgres evaluates aggregates before window functions — so this is a two-stage CTE: `intervals`
 * materializes the per-order gap first, `customer_medians` aggregates it per customer. GROUP BY in
 * the second stage still yields a row for every customer with >=1 realized order (even a single-order
 * one, whose only row has a NULL gap), which is what lets COALESCE reach them at all.
 *
 * Assumes customer_metrics already has a row per customer (BaseAggregateService, PRD §11 step 3) —
 * a customer with zero realized orders never appears in `intervals` and is left untouched (its
 * purchase_cycle_days stays NULL, "unknown" is not "the store average").
 */
final class PurchaseCycleService
{
    /** @param array{p50: int, p75: int, p90: int, sample_size: int} $storeThresholds */
    public function compute(array $storeThresholds): int
    {
        return DB::affectingStatement(
            <<<'SQL'
                WITH intervals AS (
                    SELECT
                        customer_id,
                        EXTRACT(EPOCH FROM (
                            ordered_at - LAG(ordered_at) OVER (PARTITION BY customer_id ORDER BY ordered_at)
                        )) / 86400.0 AS days
                    FROM orders
                    WHERE is_realized = true
                      AND deleted_at IS NULL
                      AND is_fully_refunded = false
                ),
                customer_medians AS (
                    SELECT
                        customer_id,
                        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY days)
                            FILTER (WHERE days > 0 AND days <= 730) AS personal_median
                    FROM intervals
                    GROUP BY customer_id
                )
                UPDATE customer_metrics cm
                SET purchase_cycle_days = COALESCE(ci.personal_median, ?)::numeric
                FROM customer_medians ci
                WHERE ci.customer_id = cm.customer_id
                SQL,
            [$storeThresholds['p50']],
        );
    }
}
