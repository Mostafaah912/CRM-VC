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
 * clv_estimated and clv_confidence are NULL together whenever there isn't enough of a pattern to
 * project from (fewer than 2 orders, or no measurable purchase cycle) — PRD's own worked SQL in §13
 * computes clv_confidence unconditionally from total_orders alone, which would hand out a 'low'
 * confidence to a customer whose clv_estimated is NULL; that contradicts the very next line of the
 * same PRD section ("wherever clv_estimated is shown, clv_confidence sits beside it") since a
 * confidence label with nothing to be confident about is not what that display contract means. Both
 * columns share the same NULL condition here so a confidence label never appears without a value.
 *
 * Display contract: clv_estimated is ALWAYS shown alongside clv_confidence. A dedicated <ClvValue />
 * React component enforces this — see P4-08. NULL clv_estimated means "insufficient data", never
 * display as zero.
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
                    WHEN total_orders < 2
                      OR purchase_cycle_days IS NULL
                      OR purchase_cycle_days <= 0
                    THEN NULL
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
