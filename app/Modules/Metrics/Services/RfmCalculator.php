<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use Illuminate\Support\Facades\DB;

/**
 * PRD §12: R/F/M scores and rfm_segment via NTILE(5). Eligible = customer_metrics.total_orders >= 1
 * AND customers.deleted_at IS NULL AND customers.status = 'active'; everyone else gets NULL scores
 * and a NULL segment — NULL, never 'lost', because "never bought" is not "churned".
 *
 * E2: recency_days orders DESC — the fewest days since the last order is bucket 5, not bucket 1.
 * E1: frequency/monetary order ASC — the highest count/spend is bucket 5. Both break ties on
 * customer_id so a run is always deterministic, never dependent on physical row order.
 *
 * E3: NTILE alone can put a one-order customer above bucket 1 (only their rank among all eligible
 * customers, not their actual order count, decides the bucket). `applyFrequencyCorrection()` runs
 * as a separate pass after scoring: `f_score = LEAST(f_score, frequency)` for frequency <= 4, which
 * is the only place a one-order customer can be forced back down to f_score 1. A customer at
 * frequency >= 5 is never capped — five one-time-bucket-5 orders is already a real f_score of 5.
 *
 * Segment mapping is a fixed CASE, most specific first (PRD §12): `cant_lose` (r=1, high f, high m —
 * a churned big spender) is checked before the general `lost` (any r=1) so a customer worth winning
 * back is never silently grouped in with everyone else who stopped buying.
 */
final class RfmCalculator
{
    public function compute(): int
    {
        $scored = $this->scoreEligible();
        $this->nullifyIneligible();
        $this->applyFrequencyCorrection();
        $this->mapSegments();

        return $scored;
    }

    private function scoreEligible(): int
    {
        return DB::affectingStatement(<<<'SQL'
            WITH eligible AS (
                SELECT cm.customer_id, cm.recency_days, cm.frequency, cm.monetary
                FROM customer_metrics cm
                JOIN customers c ON c.id = cm.customer_id
                WHERE cm.total_orders >= 1
                  AND c.deleted_at IS NULL
                  AND c.status = 'active'
            ),
            scored AS (
                SELECT
                    customer_id,
                    NTILE(5) OVER (ORDER BY recency_days DESC, customer_id ASC) AS r_score,
                    NTILE(5) OVER (ORDER BY frequency    ASC,  customer_id ASC) AS f_score,
                    NTILE(5) OVER (ORDER BY monetary     ASC,  customer_id ASC) AS m_score
                FROM eligible
            )
            UPDATE customer_metrics cm SET
                r_score   = s.r_score,
                f_score   = s.f_score,
                m_score   = s.m_score,
                rfm_score = s.r_score::text || s.f_score::text || s.m_score::text
            FROM scored s
            WHERE s.customer_id = cm.customer_id
            SQL);
    }

    private function nullifyIneligible(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customer_metrics cm SET
                r_score = NULL, f_score = NULL, m_score = NULL,
                rfm_score = NULL, rfm_segment = NULL
            FROM customers c
            WHERE c.id = cm.customer_id
              AND (cm.total_orders = 0 OR c.deleted_at IS NOT NULL OR c.status <> 'active')
            SQL);
    }

    private function applyFrequencyCorrection(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customer_metrics SET
                f_score   = LEAST(f_score, frequency),
                rfm_score = r_score::text
                           || LEAST(f_score, frequency)::text
                           || m_score::text
            WHERE frequency <= 4
              AND f_score IS NOT NULL
            SQL);
    }

    private function mapSegments(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customer_metrics SET rfm_segment = CASE
              WHEN r_score IS NULL                                  THEN NULL
              WHEN r_score >= 4 AND f_score >= 4                    THEN 'champion'
              WHEN r_score >= 3 AND f_score >= 3                    THEN 'loyal'
              WHEN r_score >= 4 AND f_score <= 2 AND frequency > 1  THEN 'promising'
              WHEN r_score = 5  AND frequency = 1                   THEN 'new_customer'
              WHEN r_score = 2  AND f_score >= 3                    THEN 'at_risk'
              WHEN r_score = 1  AND f_score >= 4 AND m_score >= 4   THEN 'cant_lose'
              WHEN r_score <= 2 AND f_score <= 2                    THEN 'hibernating'
              WHEN r_score = 1                                      THEN 'lost'
              ELSE 'promising'
            END
            SQL);
    }
}
