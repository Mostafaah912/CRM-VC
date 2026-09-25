<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use Illuminate\Support\Facades\DB;

/**
 * PRD §13: CLV historical and estimated, with a confidence label. `clv_historical = total_revenue *
 * margin_rate` — a margin proxy, not real profit, because Phase 1 has no cost data; the UI must
 * label it "تقریبی — بر پایه حاشیه میانگین" (approximate, average-margin-based), never as an exact
 * figure. `clv_estimated` is `aov * margin_rate * (365 / purchase_cycle_days) * horizon_years`: a
 * simple annualized-run-rate projection, not a probabilistic model (BG/NBD) — Phase 1 doesn't have
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
            UPDATE customer_metrics SET
                clv_historical = (total_revenue * ?::numeric)::bigint,
                clv_estimated = CASE
                    WHEN total_orders < 2
                      OR purchase_cycle_days IS NULL
                      OR purchase_cycle_days <= 0
                    THEN NULL
                    ELSE (aov * ?::numeric * (365.0 / purchase_cycle_days) * ?::numeric)::bigint
                END,
                clv_confidence = CASE
                    WHEN total_orders < 3 THEN 'low'
                    WHEN total_orders < 6 THEN 'medium'
                    ELSE 'high'
                END
            WHERE total_orders >= 1
            SQL,
            [
                config('metrics.margin_rate'),
                config('metrics.margin_rate'),
                config('metrics.horizon_years'),
            ],
        );
    }
}
