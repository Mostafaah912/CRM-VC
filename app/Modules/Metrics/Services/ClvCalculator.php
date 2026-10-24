<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use Illuminate\Support\Facades\DB;

/**
 * PRD §13: CLV historical and estimated, with a confidence label. `clv_historical = total_revenue *
 * margin_rate` — a margin proxy, not real profit, because Phase 1 has no cost data; the UI must
 * label it "تقریبی — بر پایه حاشیه میانگین" (approximate, average-margin-based), never as an exact
 * figure. `clv_estimated` is `aov * margin_rate * (365 / cycle) * horizon_years` — P8 amendment: `cycle` is the median gap between DISTINCT
 * PURCHASE DAYS (>= 1 day, >= 2 purchase days, see config/metrics.php `clv`), not `purchase_cycle_days`, and the result is capped at
 * `cap_multiple` x clv_historical: a simple annualized-run-rate projection, not a probabilistic model (BG/NBD) — Phase 1 doesn't have
 * enough order history per customer for that to be meaningful, so it is deliberately out of scope.
 *
 * clv_historical is never NULL: customer_metrics.clv_historical is NOT NULL DEFAULT 0 (P1-04), and a
 * customer with no orders has total_revenue=0, so the formula already lands on 0 with no CASE needed.
 *
 * clv_confidence is set from total_orders alone (PRD §13's own worked SQL), for every customer with
 * total_orders >= 1 — INCLUDING one whose clv_estimated is NULL (fewer than 2 orders). An earlier
 * version of this file paired both columns under one shared NULL condition, reasoning that a
 * confidence label with nothing to be confident about contradicted PRD's display-contract sentence;
 * Gate 2 (tests/fixtures/expected_metrics.json, e.g. a single-order customer with clv_estimated=null
 * and clv_confidence="low") proved that reasoning wrong — the ORIGINAL PRD formula was correct all
 * along. `clv_confidence` here reads as "how much history we have on this customer," a fact independent
 * of whether that history is enough to project a number from — a single-order customer legitimately has
 * "low" confidence in the FUTURE numbers we don't have yet, same as anyone else with few orders.
 *
 * Display contract: clv_estimated is ALWAYS shown alongside clv_confidence. A dedicated <ClvValue />
 * React component enforces this — see P4-08. NULL clv_estimated means "insufficient data", never
 * display as zero; showing a confidence label next to that "insufficient data" text is still correct
 * ("low confidence" reads naturally next to "not enough data yet").
 */
final class ClvCalculator
{
    /** @return int the number of customers with at least one order (clv_historical is meaningful for) */
    public function compute(): int
    {
        return DB::affectingStatement(<<<'SQL'
            WITH days AS (
                SELECT customer_id, (ordered_at AT TIME ZONE 'Asia/Tehran')::date AS d
                FROM orders
                WHERE is_realized = true AND deleted_at IS NULL AND is_fully_refunded = false AND customer_id IS NOT NULL
                GROUP BY customer_id, (ordered_at AT TIME ZONE 'Asia/Tehran')::date
            ),
            gaps AS (
                SELECT customer_id, (d - LAG(d) OVER (PARTITION BY customer_id ORDER BY d))::float8 AS gap FROM days
            ),
            ds AS (
                SELECT customer_id,
                       COUNT(*) AS purchase_days,
                       PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY gap) FILTER (WHERE gap >= 1 AND gap <= 730) AS cycle_days
                FROM gaps
                GROUP BY customer_id
            )
            UPDATE customer_metrics cm SET
                clv_historical = (cm.total_revenue * ?::numeric)::bigint,
                clv_estimated = CASE
                    WHEN x.purchase_days IS NULL
                      OR x.purchase_days < ?
                      OR x.cycle_days IS NULL
                      OR x.cycle_days < 1
                    THEN NULL
                    ELSE LEAST(
                        cm.aov * ?::numeric * (365.0 / x.cycle_days) * ?::numeric,
                        ?::numeric * ((cm.total_revenue * ?::numeric)::bigint)
                    )::bigint
                END,
                clv_confidence = CASE
                    WHEN cm.total_orders < 3 THEN 'low'
                    WHEN cm.total_orders < 6 THEN 'medium'
                    ELSE 'high'
                END
            FROM (
                SELECT m.customer_id, ds.purchase_days, ds.cycle_days
                FROM customer_metrics m
                LEFT JOIN ds ON ds.customer_id = m.customer_id
                WHERE m.total_orders >= 1
            ) x
            WHERE cm.customer_id = x.customer_id
            SQL,
            [
                config('metrics.margin_rate'),
                config('metrics.clv.min_purchase_days'),
                config('metrics.margin_rate'),
                config('metrics.horizon_years'),
                config('metrics.clv.cap_multiple'),
                config('metrics.margin_rate'),
            ],
        );
    }
}
