<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use Illuminate\Support\Facades\DB;

/**
 * PRD §14 steps 3-4: churn_risk_score, churn_risk_level, the mandatory Persian churn_reason, and
 * expected_next_order_at. Never touches a name, contact detail or any other customer column: reads
 * only customer_metrics and the timing of a customer's own orders.
 *
 * ratio = recency_days / purchase_cycle_days, scaled by 40 so a customer exactly overdue by one
 * cycle (ratio=1) sits at 40, twice overdue (ratio=2) at 80, and 2.5x or more overdue caps at 100 —
 * the store's own thresholds (p75/p90), not this score, decide the discrete level (below), so this
 * scaling only needs to spread scores usefully across that range, not hit any particular threshold.
 *
 * trend_penalty only applies at total_orders >= 3: a customer's first order has no prior order to
 * form an interval from, and their second order's single interval already IS their whole cycle
 * baseline — penalizing it as "trending slower" would be comparing a number to itself. Only from the
 * third order does "this gap is unusually long for THIS customer" become a meaningful signal, judged
 * against their own last real interval (read from `orders`, not from customer_metrics) versus 1.5x
 * their purchase cycle.
 *
 * churn_reason is never optional wherever churn_risk_level is set (CLAUDE.md §4: a risk score with
 * no explanation is not trusted, and P4-08's UI must not show a bare number). It is built by plain
 * SQL string concatenation with bound values only — never sprintf/interpolation with a `$` — and
 * never references a name, contact detail, or any other PII column; only recency_days,
 * purchase_cycle_days and the store's own p75 threshold ever appear in it.
 */
final class ChurnCalculator
{
    /**
     * @param  array{p50: int, p75: int, p90: int, sample_size?: int}  $storeThresholds
     * @return int the number of customers with at least one order (churn was actually computed for)
     */
    public function compute(array $storeThresholds): int
    {
        $scored = $this->scoreEligible($storeThresholds);
        $this->nullifyProspects();

        return $scored;
    }

    /** @param array{p50: int, p75: int, p90: int, sample_size?: int} $storeThresholds */
    private function scoreEligible(array $storeThresholds): int
    {
        return DB::affectingStatement(
            <<<'SQL'
                WITH order_intervals AS (
                    SELECT
                        customer_id,
                        ordered_at,
                        EXTRACT(EPOCH FROM (
                            ordered_at - LAG(ordered_at) OVER (PARTITION BY customer_id ORDER BY ordered_at ASC)
                        )) / 86400.0 AS interval_days
                    FROM orders
                    WHERE is_realized = true
                      AND deleted_at IS NULL
                      AND is_fully_refunded = false
                ),
                last_intervals AS (
                    SELECT DISTINCT ON (customer_id)
                        customer_id,
                        interval_days AS last_interval_days
                    FROM order_intervals
                    WHERE interval_days IS NOT NULL
                    ORDER BY customer_id, ordered_at DESC
                )
                UPDATE customer_metrics cm SET
                    churn_risk_score = GREATEST(0, LEAST(100, (
                        LEAST(100.0, (cm.recency_days::float / NULLIF(cm.purchase_cycle_days, 0)) * 40)
                        + CASE WHEN cm.total_orders = 1 THEN 15 ELSE 0 END
                        + CASE WHEN cm.total_orders >= 3
                               AND li.last_interval_days > cm.purchase_cycle_days * 1.5
                               THEN 10 ELSE 0 END
                        - CASE WHEN cm.m_score = 5 THEN 5 ELSE 0 END
                    )::integer)),
                    churn_risk_level = CASE
                        WHEN cm.recency_days > ?::integer THEN 'lost'
                        WHEN cm.recency_days > ?::integer THEN 'high'
                        WHEN cm.recency_days > ?::integer THEN 'medium'
                        ELSE 'low'
                    END,
                    churn_reason = cm.recency_days::text
                        || ' روز از آخرین خرید گذشته؛ چرخه خرید این مشتری '
                        || cm.purchase_cycle_days::integer::text
                        || ' روز است (آستانه فروشگاه: ' || ?::text || ' روز)',
                    expected_next_order_at = CASE
                        WHEN cm.last_order_at IS NOT NULL
                        THEN cm.last_order_at + (cm.purchase_cycle_days::integer * INTERVAL '1 day')
                        ELSE NULL
                    END
                FROM customers c
                LEFT JOIN last_intervals li ON li.customer_id = c.id
                WHERE c.id = cm.customer_id
                  AND c.deleted_at IS NULL
                  AND cm.total_orders >= 1
                SQL,
            [
                $storeThresholds['p90'] * 2,
                $storeThresholds['p90'],
                $storeThresholds['p75'],
                $storeThresholds['p75'],
            ],
        );
    }

    private function nullifyProspects(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customer_metrics cm SET
                churn_risk_score = NULL,
                churn_risk_level = NULL,
                churn_reason = NULL,
                expected_next_order_at = NULL
            FROM customers c
            WHERE c.id = cm.customer_id
              AND (cm.total_orders = 0 OR c.deleted_at IS NOT NULL)
            SQL);
    }
}
