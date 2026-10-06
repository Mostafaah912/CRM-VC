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
 *
 * P6-20, product-owner decision (ARCHITECTURE.md): `config('metrics.monetary.mode')` — 'lifetime'
 * (the default, PRD §12 as written above, untouched) or 'recent_window'. R and F are NEVER affected
 * by this; `scoreEligible()` below still always runs first and still always computes an NTILE
 * `m_score` from lifetime `monetary` exactly as before — in `recent_window` mode, `overrideMonetaryScore()`
 * then replaces ONLY `m_score` (and rebuilds `rfm_score`) using `monetary_recent` and a set of cut-points
 * computed from that window's own distribution, never NTILE's equal-COUNT buckets: `log(amount)`,
 * then a robust z-score (`(log(amount) - median_log) / (1.4826 * MAD_log)`, the standard MAD-to-stddev
 * consistency constant under normality) cut at z = -0.84/-0.25/+0.25/+0.84 — the same four points that
 * split a normal distribution into quintiles, so the result reads like a familiar 1..5 RFM score, but
 * from the window's actual shape rather than from rank, and unmoved by one extreme outlier (median/MAD
 * are robust statistics; min/max or mean/stddev are not). A customer with no realized purchase in the
 * window gets `m_score = 1` directly, never run through the cut-points — same bucket as the lowest
 * real purchasers in that window, by design (PRD deviation, documented in ARCHITECTURE.md "P6-20").
 */
final class RfmCalculator
{
    private const MONETARY_Z_CUTS = [-0.84, -0.25, 0.25, 0.84];

    private const MAD_TO_STDDEV = 1.4826;

    /** @var array{window_days: int, sample_size: int, c1: int|null, c2: int|null, c3: int|null, c4: int|null}|null */
    private ?array $lastMonetaryCutpoints = null;

    public function compute(): int
    {
        $scored = $this->scoreEligible();

        $this->lastMonetaryCutpoints = null;
        if ($this->monetaryMode() === 'recent_window') {
            $cutpoints = $this->computeMonetaryCutpoints();
            $this->overrideMonetaryScore($cutpoints);
            $this->rebuildRfmScoreString();
            $this->lastMonetaryCutpoints = $cutpoints;
        }

        $this->nullifyIneligible();
        $this->applyFrequencyCorrection();
        $this->mapSegments();

        return $scored;
    }

    /**
     * The cut-points this run actually scored M with, or null in 'lifetime' mode — the one place
     * `MetricsRecomputeService` reads them to persist into `metric_runs.thresholds` (mirroring
     * `ChurnThresholdService::saveToRun()`), so `MetricsGuideService` reads the SAME values that were
     * actually used, never a second, possibly-drifted recomputation.
     *
     * @return array{window_days: int, sample_size: int, c1: int|null, c2: int|null, c3: int|null, c4: int|null}|null
     */
    public function monetaryCutpoints(): ?array
    {
        return $this->lastMonetaryCutpoints;
    }

    private function monetaryMode(): string
    {
        return (string) config('metrics.monetary.mode', 'lifetime');
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

    /**
     * The window's own log-amount median and MAD, over eligible customers who actually have a
     * `monetary_recent` purchase (> 0 — a customer with no window purchase has NULL there and never
     * reaches this query at all). `percentile_cont(0.5)` for both: the median itself, then the median
     * of absolute deviations from it — two passes in one statement via a CTE, the same shape
     * `ChurnThresholdService::percentiles()` already uses for its own percentile_cont query. Returns
     * null when nobody in `eligible` has a window purchase at all (the `purchasers` CTE is empty, so
     * the final `FROM purchasers, med` cross join yields zero rows) — {@see overrideMonetaryScore()}
     * then gives every eligible customer m_score 1, which is already correct in that case (100% of
     * them have "no purchase in the window").
     *
     * @return array{window_days: int, sample_size: int, c1: int|null, c2: int|null, c3: int|null, c4: int|null}
     */
    private function computeMonetaryCutpoints(): array
    {
        $windowDays = (int) config('metrics.monetary.window_days', 60);

        $stats = DB::selectOne(<<<'SQL'
            WITH eligible AS (
                SELECT cm.customer_id
                FROM customer_metrics cm
                JOIN customers c ON c.id = cm.customer_id
                WHERE cm.total_orders >= 1
                  AND c.deleted_at IS NULL
                  AND c.status = 'active'
            ),
            purchasers AS (
                SELECT LN(cm.monetary_recent) AS log_amount
                FROM customer_metrics cm
                JOIN eligible e ON e.customer_id = cm.customer_id
                WHERE cm.monetary_recent IS NOT NULL AND cm.monetary_recent > 0
            ),
            med AS (
                SELECT
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY log_amount) AS median_log,
                    COUNT(*)::integer AS sample_size
                FROM purchasers
            )
            SELECT
                med.median_log,
                med.sample_size,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY ABS(purchasers.log_amount - med.median_log)) AS mad_log
            FROM purchasers, med
            GROUP BY med.median_log, med.sample_size
            SQL);

        if ($stats === null) {
            return ['window_days' => $windowDays, 'sample_size' => 0, 'c1' => null, 'c2' => null, 'c3' => null, 'c4' => null];
        }

        $sampleSize = (int) $stats->sample_size;
        $madLog = (float) $stats->mad_log;

        // No spread (every window purchaser logged the same amount, or there is only one): the 4
        // cut-points would all collapse onto the same value. overrideMonetaryScore() special-cases
        // this (c1 === null) to the one answer that respects "equal values get equal score" — every
        // purchaser lands in the middle band, 3.
        if ($madLog <= 0.0) {
            return ['window_days' => $windowDays, 'sample_size' => $sampleSize, 'c1' => null, 'c2' => null, 'c3' => null, 'c4' => null];
        }

        $medianLog = (float) $stats->median_log;
        $cutoffs = array_map(
            fn (float $z): int => (int) round(exp($medianLog + $z * self::MAD_TO_STDDEV * $madLog)),
            self::MONETARY_Z_CUTS,
        );

        return [
            'window_days' => $windowDays,
            'sample_size' => $sampleSize,
            'c1' => $cutoffs[0], 'c2' => $cutoffs[1], 'c3' => $cutoffs[2], 'c4' => $cutoffs[3],
        ];
    }

    /**
     * Replaces ONLY `m_score` (never r_score/f_score) with the cut-point-based band: below c1 (or no
     * window purchase at all, or amount <= 0) is 1, [c1,c2) is 2, [c2,c3) is 3, [c3,c4) is 4, c4-and-up
     * is 5 — four half-open bands, no overlap, no gap. `c1 === null` signals the degenerate case from
     * {@see computeMonetaryCutpoints()} (no spread, or no window purchasers at all): every actual
     * purchaser gets 3 (the single band left, once there is no distribution to cut), everyone else
     * (no purchase in the window) still gets 1.
     *
     * @param  array{c1: int|null, c2: int|null, c3: int|null, c4: int|null}  $cutpoints
     */
    private function overrideMonetaryScore(array $cutpoints): void
    {
        if ($cutpoints['c1'] === null) {
            DB::statement(<<<'SQL'
                UPDATE customer_metrics cm SET
                    m_score = CASE WHEN monetary_recent > 0 THEN 3 ELSE 1 END
                FROM customers c
                WHERE c.id = cm.customer_id
                  AND cm.total_orders >= 1 AND c.deleted_at IS NULL AND c.status = 'active'
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            UPDATE customer_metrics cm SET
                m_score = CASE
                    WHEN monetary_recent IS NULL OR monetary_recent <= 0 THEN 1
                    WHEN monetary_recent < ? THEN 1
                    WHEN monetary_recent < ? THEN 2
                    WHEN monetary_recent < ? THEN 3
                    WHEN monetary_recent < ? THEN 4
                    ELSE 5
                END
            FROM customers c
            WHERE c.id = cm.customer_id
              AND cm.total_orders >= 1 AND c.deleted_at IS NULL AND c.status = 'active'
            SQL, [$cutpoints['c1'], $cutpoints['c2'], $cutpoints['c3'], $cutpoints['c4']]);
    }

    /** Re-concatenates `rfm_score` from the three CURRENT score columns — used after {@see overrideMonetaryScore()} replaces m_score, so the string reflects it (frequency correction, next, rebuilds it again for frequency <= 4 anyway). */
    private function rebuildRfmScoreString(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customer_metrics
            SET rfm_score = r_score::text || f_score::text || m_score::text
            WHERE r_score IS NOT NULL
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

    /**
     * P6-21, product-owner decision (ARCHITECTURE.md): `cant_lose` always reads the LIFETIME
     * `monetary` NTILE, never the windowed `m_score` — in `recent_window` mode, `m_score` has been
     * overridden to the 60-day value by {@see overrideMonetaryScore()}, but a customer worth winning
     * back (`cant_lose`'s whole point) is, by definition, someone who stopped buying — they almost
     * never have a window purchase, so gating this one condition on the windowed score made the
     * segment collapse to empty (documented in `docs/architecture/sprint-6.md`, "P6-20"'s own impact
     * report). In `lifetime` mode this is a no-op: `m_score` already IS the lifetime value, so the
     * fresh NTILE computed here is identical to it — `mapSegmentsLifetime()` below is kept byte-for-
     * byte as PRD §12 wrote it (GATE 2), never routed through the CTE at all.
     */
    private function mapSegments(): void
    {
        if ($this->monetaryMode() === 'recent_window') {
            $this->mapSegmentsWithLifetimeCantLose();

            return;
        }

        $this->mapSegmentsLifetime();
    }

    private function mapSegmentsLifetime(): void
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

    /**
     * Identical CASE to {@see mapSegmentsLifetime()}, except `cant_lose`'s M check reads
     * `lm.m_score_lifetime` (a fresh `NTILE(5) OVER (ORDER BY monetary ASC, customer_id ASC)`,
     * scoped to the same already-nullified eligible rows via `r_score IS NOT NULL`) instead of the
     * windowed `m_score` column. Only rows present in `lifetime_m` (i.e. eligible) are touched by this
     * UPDATE; ineligible rows already have `rfm_segment = NULL` from `nullifyIneligible()`, which runs
     * earlier in {@see compute()}, so they are correctly left untouched here rather than re-nulled.
     */
    private function mapSegmentsWithLifetimeCantLose(): void
    {
        DB::statement(<<<'SQL'
            WITH lifetime_m AS (
                SELECT customer_id, NTILE(5) OVER (ORDER BY monetary ASC, customer_id ASC) AS m_score_lifetime
                FROM customer_metrics
                WHERE r_score IS NOT NULL
            )
            UPDATE customer_metrics cm SET rfm_segment = CASE
              WHEN cm.r_score >= 4 AND cm.f_score >= 4                      THEN 'champion'
              WHEN cm.r_score >= 3 AND cm.f_score >= 3                      THEN 'loyal'
              WHEN cm.r_score >= 4 AND cm.f_score <= 2 AND cm.frequency > 1 THEN 'promising'
              WHEN cm.r_score = 5  AND cm.frequency = 1                     THEN 'new_customer'
              WHEN cm.r_score = 2  AND cm.f_score >= 3                      THEN 'at_risk'
              WHEN cm.r_score = 1  AND cm.f_score >= 4 AND lm.m_score_lifetime >= 4 THEN 'cant_lose'
              WHEN cm.r_score <= 2 AND cm.f_score <= 2                      THEN 'hibernating'
              WHEN cm.r_score = 1                                          THEN 'lost'
              ELSE 'promising'
            END
            FROM lifetime_m lm
            WHERE lm.customer_id = cm.customer_id
            SQL);
    }
}
