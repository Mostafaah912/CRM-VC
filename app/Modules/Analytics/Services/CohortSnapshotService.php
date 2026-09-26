<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\CohortSnapshotSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRD §15's cohort/retention table — one TRUNCATE + INSERT...SELECT over the full (cohort_month x
 * period_number) grid, never a PHP loop over customers/orders (CLAUDE.md §3). Reads `customer_metrics`/
 * `orders`/`customers` with the query builder / raw SQL only; Analytics is a documented, blanket Rule 7
 * exception (same as P6-01/P6-02) — no arch-test change needed. TRUNCATE matches PRD's own literal SQL
 * and the migration's own docblock ("TRUNCATE-and-rebuild table"); no reader of this table exists yet
 * (checked before writing this), same reasoning already used for P6-01.
 *
 * `cohort_month` is never recomputed here — it's read straight from `customer_metrics.cohort_month`
 * (already "jalali month of first realized order" per PRD §15/D4, written by `BaseAggregateService` via
 * `to_jalali_month()`), the one place this fact already lives. This makes this job's PRD §22 chain
 * position load-bearing, the same way P6-02 already documented for `first_order_at`: it must run after
 * `RecomputeMetricsJob('full')` for `cohort_month` to be fresh.
 *
 * "Realized order" reuses `BaseAggregateService`'s exact definition (`is_realized = true AND
 * is_fully_refunded = false AND deleted_at IS NULL`) — the same choice P6-02 made for `daily_metrics`,
 * for the same reason: one definition of "counted order" across Metrics/Analytics.
 *
 * PRD §15 gives no fixed number of periods to generate per cohort. The grid is bounded to
 * `config('metrics.horizon_years') * 12` periods (24 by default) — reusing the CLV horizon already
 * established in PRD §12/D12 rather than inventing an unrelated constant — because a matrix needs a
 * *rectangular* shape: every cohort gets a row for every period up to that ceiling, even a period that
 * hasn't started yet, so a young cohort's not-yet-elapsed periods can be flagged `is_mature = false`
 * (PRD's own "immature cohort trap": a matrix cell must show grey/flagged, never a bare 0) instead of
 * simply not existing. Generating only up to each cohort's own currently-elapsed period would make
 * every generated row trivially mature and leave `is_mature` dead — the opposite of what the column and
 * warning are for.
 *
 * `is_mature`'s literal PRD formula (`jalali_month_diff(cohort_month, to_jalali_month(now())) >=
 * period_number`) marks the *current, still-in-progress* month as mature (`>=`, the boundary case),
 * even though PRD's own prose one line above it says "existed for N full months" (which would read as
 * `>`, strictly before the current month). Implemented exactly per the boxed SQL, not the prose — the
 * same precedence PRD's own boxed formulas get everywhere else in this codebase (e.g. `BaseAggregateService`
 * mirrors PRD §11's SQL almost verbatim). Discrepancy documented, not silently resolved either way.
 */
final class CohortSnapshotService
{
    /** `$asOf` (Gate-2-style determinism, see BaseAggregateService/DailyMetricsService): defaults to now. */
    public function rebuild(?CarbonImmutable $asOf = null): CohortSnapshotSummary
    {
        $start = microtime(true);
        $maxPeriod = (int) (config('metrics.horizon_years', 2.0) * 12);
        $asOfString = ($asOf ?? CarbonImmutable::now())->format('Y-m-d H:i:sP');

        $rowsWritten = DB::transaction(function () use ($maxPeriod, $asOfString): int {
            DB::table('cohort_snapshots')->truncate();

            return DB::affectingStatement(<<<'SQL'
                WITH cohorts AS (
                    SELECT cm.cohort_month, COUNT(*)::integer AS cohort_size
                    FROM customer_metrics cm
                    JOIN customers c ON c.id = cm.customer_id
                    WHERE cm.cohort_month IS NOT NULL AND c.deleted_at IS NULL
                    GROUP BY cm.cohort_month
                ),
                periods AS (
                    SELECT generate_series(0, ?::int) AS period_number
                ),
                grid AS (
                    SELECT cohorts.cohort_month, cohorts.cohort_size, periods.period_number
                    FROM cohorts CROSS JOIN periods
                ),
                activity AS (
                    SELECT
                        cm.cohort_month,
                        jalali_month_diff(cm.cohort_month, to_jalali_month(o.ordered_at)) AS period_number,
                        o.customer_id,
                        o.net_revenue
                    FROM orders o
                    JOIN customer_metrics cm ON cm.customer_id = o.customer_id
                    JOIN customers c ON c.id = o.customer_id
                    WHERE o.is_realized = true
                      AND o.is_fully_refunded = false
                      AND o.deleted_at IS NULL
                      AND c.deleted_at IS NULL
                      AND cm.cohort_month IS NOT NULL
                ),
                agg AS (
                    SELECT
                        cohort_month,
                        period_number,
                        COUNT(DISTINCT customer_id)::integer AS active_customers,
                        COUNT(*)::integer AS orders_count,
                        COALESCE(SUM(net_revenue), 0)::bigint AS revenue
                    FROM activity
                    GROUP BY cohort_month, period_number
                )
                INSERT INTO cohort_snapshots (
                    cohort_month, period_number, cohort_size, active_customers, retention_rate,
                    orders_count, revenue, cumulative_revenue, is_mature, computed_at
                )
                SELECT
                    grid.cohort_month,
                    grid.period_number,
                    grid.cohort_size,
                    COALESCE(agg.active_customers, 0),
                    ROUND(COALESCE(agg.active_customers, 0)::numeric / NULLIF(grid.cohort_size, 0), 4),
                    COALESCE(agg.orders_count, 0),
                    COALESCE(agg.revenue, 0),
                    SUM(COALESCE(agg.revenue, 0)) OVER (
                        PARTITION BY grid.cohort_month ORDER BY grid.period_number
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ),
                    jalali_month_diff(grid.cohort_month, to_jalali_month(?::timestamptz)) >= grid.period_number,
                    NOW()
                FROM grid
                LEFT JOIN agg ON agg.cohort_month = grid.cohort_month AND agg.period_number = grid.period_number
                SQL,
                [$maxPeriod, $asOfString],
            );
        });

        return new CohortSnapshotSummary(
            rowsWritten: $rowsWritten,
            elapsedMs: (int) round((microtime(true) - $start) * 1000),
        );
    }
}
