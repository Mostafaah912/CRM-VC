<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\NDayRetentionResult;
use App\Modules\Analytics\Support\RepeatPurchaseRateResult;
use App\Modules\Analytics\Support\ReturningRevenueShareResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRD §15's "معیارهای سطح فروشگاه" (store-level metrics) — three cheap reads over already-materialized
 * `customer_metrics`/`orders`, computed on demand rather than stored. PRD §22's job list has no dedicated
 * job for these (unlike `BuildCohortSnapshotsJob`/`BuildDailyMetricsJob`), and each query here reads a
 * small, already-rolled-up table (`customer_metrics`) or a bounded join, not raw per-order iteration —
 * satisfying PRD §18's "no heavy aggregation at page load" the same way the RFM/Churn pages already do
 * (reading `customer_metrics` directly), not by adding yet another cache table for numbers this cheap to
 * recompute. No Job, no migration, no TRUNCATE: nothing here is ever written back to the database.
 *
 * "Realized order" reuses `BaseAggregateService`'s exact definition, the same choice P6-02/P6-03 already
 * made, for one definition of "counted order" across Metrics/Analytics.
 */
final class RetentionService
{
    /** PRD §15: `COUNT(total_orders>=2) / COUNT(total_orders>=1)`. Never fabricates a 0 with no data. */
    public function repeatPurchaseRate(): RepeatPurchaseRateResult
    {
        $row = DB::table('customer_metrics')
            ->join('customers', 'customers.id', '=', 'customer_metrics.customer_id')
            ->whereNull('customers.deleted_at')
            ->selectRaw('
                COUNT(*) FILTER (WHERE customer_metrics.total_orders >= 2)::integer AS repeat_customers,
                COUNT(*) FILTER (WHERE customer_metrics.total_orders >= 1)::integer AS eligible_customers
            ')
            ->first();

        $insufficientData = $row->eligible_customers === 0;

        return new RepeatPurchaseRateResult(
            eligibleCustomers: $row->eligible_customers,
            repeatCustomers: $row->repeat_customers,
            rate: $insufficientData ? null : round($row->repeat_customers / $row->eligible_customers, 4),
            insufficientData: $insufficientData,
        );
    }

    /**
     * PRD §15: `SUM(net_revenue WHERE ordered_at > first_order_at) / SUM(net_revenue)`. Order-level, not
     * day-level: a second same-day order after the acquisition order still counts as returning here,
     * unlike `DailyMetricsService`'s day-granularity new/repeat split (P6-02) — a deliberate difference,
     * since PRD's formula compares against the exact `first_order_at` instant, not a calendar day.
     *
     * No `is_fully_refunded` filter here, unlike the other two methods below and `BaseAggregateService`/
     * `DailyMetricsService`/`CohortSnapshotService`: for a fully refunded order `net_revenue` (total -
     * refunded_total) is already exactly 0 by definition, so summing it in or filtering it out never
     * changes the result — the filter would be inert, not a real exclusion.
     */
    public function returningRevenueShare(): ReturningRevenueShareResult
    {
        $row = DB::table('orders')
            ->join('customer_metrics', 'customer_metrics.customer_id', '=', 'orders.customer_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('orders.is_realized', true)
            ->whereNull('orders.deleted_at')
            ->whereNull('customers.deleted_at')
            ->selectRaw('
                COALESCE(SUM(CASE WHEN orders.ordered_at > customer_metrics.first_order_at THEN orders.net_revenue ELSE 0 END), 0)::bigint AS returning_revenue,
                COALESCE(SUM(orders.net_revenue), 0)::bigint AS total_revenue
            ')
            ->first();

        $insufficientData = $row->total_revenue === 0;

        return new ReturningRevenueShareResult(
            totalRevenue: $row->total_revenue,
            returningRevenue: $row->returning_revenue,
            share: $insufficientData ? null : round($row->returning_revenue / $row->total_revenue, 4),
            insufficientData: $insufficientData,
        );
    }

    /**
     * PRD §15: "N-day retention: only over MATURE cohorts (first_order_at <= now() - n days)". Maturity
     * here is a day-granularity, per-customer cutoff — a different concept from `cohort_snapshots.is_mature`
     * (P6-03's month-granularity, per-cohort-month flag) — so this never reads that table. An immature
     * customer (first_order_at within the last N days) is excluded from both the numerator and the
     * denominator entirely, even if they already placed a second order early: counting them would let an
     * incomplete, biased sample inflate the rate — PRD's own "immature cohort trap".
     *
     * `$asOf` (Gate-2-style determinism, see BaseAggregateService): defaults to now. Passed as an absolute
     * instant, so it gives the same result regardless of which timezone the caller expressed it in.
     */
    public function nDayRetention(int $days, ?CarbonImmutable $asOf = null): NDayRetentionResult
    {
        $asOfString = ($asOf ?? CarbonImmutable::now())->format('Y-m-d H:i:sP');

        $row = DB::selectOne(<<<'SQL'
            WITH mature AS (
                SELECT cm.customer_id, cm.first_order_at
                FROM customer_metrics cm
                JOIN customers c ON c.id = cm.customer_id
                WHERE cm.first_order_at IS NOT NULL
                  AND c.deleted_at IS NULL
                  AND cm.first_order_at <= ?::timestamptz - (?::int * INTERVAL '1 day')
            )
            SELECT
                COUNT(DISTINCT m.customer_id)::integer AS mature_customers,
                COUNT(DISTINCT o.customer_id)::integer AS returned_customers
            FROM mature m
            LEFT JOIN orders o
                ON o.customer_id = m.customer_id
               AND o.is_realized = true
               AND o.is_fully_refunded = false
               AND o.deleted_at IS NULL
               AND o.ordered_at > m.first_order_at
               AND o.ordered_at <= m.first_order_at + (?::int * INTERVAL '1 day')
            SQL,
            [$asOfString, $days, $days],
        );

        $insufficientData = $row->mature_customers === 0;

        return new NDayRetentionResult(
            days: $days,
            matureCustomers: $row->mature_customers,
            returnedCustomers: $row->returned_customers,
            retentionRate: $insufficientData ? null : round($row->returned_customers / $row->mature_customers, 4),
            insufficientData: $insufficientData,
        );
    }
}
