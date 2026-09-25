<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use Illuminate\Support\Facades\DB;

/**
 * PRD §11: lifecycle_stage from total_orders and recency_days against the store's real p50/p75/p90.
 * The column lives on `customers` (CHECK'd there since P0-05), not on customer_metrics — that table
 * has no such column at all — so this writes `customers.lifecycle_stage`, joined to customer_metrics
 * by customer_id, never the other way around.
 *
 * CASE order runs specific -> general (prospect first, lost last), the same discipline as the RFM
 * segment mapping (P4-03): `at_risk`/`dormant`/`lost` only apply once every order-count-based branch
 * above them has already failed to match, so a recency-only condition never shadows a more specific
 * order-count one.
 *
 * Boundary convention: `<= p50` is `new`, `p50+1..p75` is `active` — a customer exactly AT a
 * threshold belongs to the closer, less-severe stage, never the next one out (also true at p75 for
 * at_risk and p90*2 for lost).
 *
 * Guard: total_orders >= 1 with a null recency_days should never happen after P4-01 (recency_days is
 * only null when total_orders=0), and `customers.lifecycle_stage` is NOT NULL — so this is not "set
 * it to NULL" (impossible) but "skip the row", leaving whatever stage it already had rather than
 * risk writing a wrong one from a CASE that can't fully account for that measurement.
 */
final class LifecycleStageResolver
{
    /** @param array{p50: int, p75: int, p90: int, sample_size?: int} $storeThresholds */
    public function resolve(array $storeThresholds): int
    {
        $p50 = $storeThresholds['p50'];
        $p75 = $storeThresholds['p75'];
        $p90 = $storeThresholds['p90'];
        $p90Times2 = $p90 * 2;

        return DB::affectingStatement(
            <<<'SQL'
                UPDATE customers c SET lifecycle_stage = CASE
                    WHEN cm.total_orders = 0 THEN 'prospect'
                    WHEN cm.total_orders = 1 AND cm.recency_days <= ?::integer THEN 'new'
                    WHEN cm.total_orders = 1 AND cm.recency_days <= ?::integer THEN 'active'
                    WHEN cm.total_orders IN (2, 3) AND cm.recency_days <= ?::integer THEN 'repeat'
                    WHEN cm.total_orders >= 4 AND cm.recency_days <= ?::integer THEN 'loyal'
                    WHEN cm.recency_days > ?::integer AND cm.recency_days <= ?::integer THEN 'at_risk'
                    WHEN cm.recency_days > ?::integer AND cm.recency_days <= ?::integer THEN 'dormant'
                    WHEN cm.recency_days > ?::integer THEN 'lost'
                    ELSE c.lifecycle_stage
                END
                FROM customer_metrics cm
                WHERE cm.customer_id = c.id
                  AND c.deleted_at IS NULL
                  AND (cm.total_orders = 0 OR cm.recency_days IS NOT NULL)
                SQL,
            [$p50, $p75, $p75, $p75, $p75, $p90, $p90, $p90Times2, $p90Times2],
        );
    }
}
