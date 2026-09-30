<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Enums\AffinityLevel;
use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Metrics\Services\ChurnDistributionService;
use App\Modules\Metrics\Services\RfmPageService;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRD §18's Dashboard, PRD §07's `AnalyticsService::dashboard()`: one read-only composition over the
 * tables Sprint 6 already builds — `daily_metrics` (P6-02), `cohort_snapshots`/`product_affinities`
 * (P6-03/05, via their own read methods), `RetentionService`'s two lifetime metrics (P6-04), and
 * Metrics' own `RfmPageService`/`ChurnDistributionService` for RFM/churn (Metrics owns `customer_metrics`
 * and its `RfmSegment`/`ChurnRiskLevel` enums — the module boundary lets Analytics reach them only
 * through a public Service, never their Enums directly, so those two reads are NOT duplicated here).
 * Nothing is computed here that isn't already a plain read of an already-materialized row — PRD §18:
 * "هیچ تجمیع سنگینی در لحظه بارگذاری" (no heavy aggregation at load time).
 *
 * Only the KPI totals/trend/new-vs-returning numbers are period-scoped (`daily_metrics` is the only
 * source table with a date dimension); RFM/churn/cohort/affinity are current-state snapshots with no
 * historical dimension in their own tables, so the dashboard's period filter does not apply to them —
 * a scope decision, not an oversight (see the end-of-task report).
 *
 * "Active segments" and "system health" (also named in PRD §18's widget list) are deliberately NOT
 * queried here: PRD §07's own module dependency table does not allow Analytics -> Segments or
 * Analytics -> Sync, and adding either would be an architecture change beyond this task. Both are
 * served as plain link-out cards in the React page instead, gated by the existing shared `useCan()`
 * permission check — no new backend read needed. "AI summary" is omitted outright: Sprint 7 (AI
 * Analyst) has not started, so there is no real data to show.
 */
final class AnalyticsService
{
    private const TREND_KEEP_ROWS = ['date', 'jalali_date', 'orders_count', 'net_revenue', 'aov'];

    public function __construct(
        private readonly RetentionService $retention,
        private readonly CohortSnapshotService $cohorts,
        private readonly AffinityService $affinity,
        private readonly RfmPageService $rfm,
        private readonly ChurnDistributionService $churn,
    ) {}

    /**
     * A plain, Inertia-ready array (same convention as `RfmPageService::getData()`): the Dashboard page
     * is this method's one real caller, so the page-shaped array is built here, not left to the
     * Controller.
     *
     * @return array{
     *     period: array{from: string, to: string, previous_from: string, previous_to: string, from_jalali: string, to_jalali: string, previous_from_jalali: string, previous_to_jalali: string},
     *     current: array{orders_count: int, net_revenue: int, aov: int, customers_new: int, customers_repeat: int},
     *     previous: array{orders_count: int, net_revenue: int, aov: int, customers_new: int, customers_repeat: int},
     *     trend: list<array{date: string, jalali_date: string, orders_count: int, net_revenue: int, aov: int}>,
     *     repeat_purchase_rate: array{eligible_customers: int, repeat_customers: int, rate: float|null, insufficient_data: bool},
     *     returning_revenue_share: array{total_revenue: int, returning_revenue: int, share: float|null, insufficient_data: bool},
     *     rfm_distribution: array<string, int>,
     *     churn_distribution: array<string, int>,
     *     value_at_risk: int,
     *     cohort_matrix: list<array{cohort_month: string, cohort_size: int, periods: list<array{period_number: int, retention_rate: float|null, is_mature: bool, active_customers: int}>}>,
     *     top_affinity: list<array{entity_a_id: int, entity_b_id: int, co_customers: int, support: float, confidence: float, lift: float, level: string}>,
     * }
     */
    public function dashboard(DashboardPeriod $period): array
    {
        $repeatPurchaseRate = $this->retention->repeatPurchaseRate();
        $returningRevenueShare = $this->retention->returningRevenueShare();
        $churnSummary = $this->churn->summary();

        return [
            'period' => [
                'from' => $period->from,
                'to' => $period->to,
                'previous_from' => $period->previousFrom,
                'previous_to' => $period->previousTo,
                // Jalali equivalents (App\Support\JalaliDate, CLAUDE.md §2 — the one conversion path):
                // DrillRequest only accepts a Jalali from/to, matching the dashboard's own filter form
                // contract, so the page needs these ready-made rather than converting dates in TS.
                'from_jalali' => JalaliDate::format(CarbonImmutable::parse($period->from), '/'),
                'to_jalali' => JalaliDate::format(CarbonImmutable::parse($period->to), '/'),
                // P6-11: the page's "compared to the previous period" text needs these too — Jalali
                // everywhere the period is shown, never a raw Gregorian date (CLAUDE.md §2).
                'previous_from_jalali' => JalaliDate::format(CarbonImmutable::parse($period->previousFrom), '/'),
                'previous_to_jalali' => JalaliDate::format(CarbonImmutable::parse($period->previousTo), '/'),
            ],
            'current' => $this->periodTotals($period->from, $period->to),
            'previous' => $this->periodTotals($period->previousFrom, $period->previousTo),
            'trend' => $this->trend($period->from, $period->to),
            'repeat_purchase_rate' => [
                'eligible_customers' => $repeatPurchaseRate->eligibleCustomers,
                'repeat_customers' => $repeatPurchaseRate->repeatCustomers,
                'rate' => $repeatPurchaseRate->rate,
                'insufficient_data' => $repeatPurchaseRate->insufficientData,
            ],
            'returning_revenue_share' => [
                'total_revenue' => $returningRevenueShare->totalRevenue,
                'returning_revenue' => $returningRevenueShare->returningRevenue,
                'share' => $returningRevenueShare->share,
                'insufficient_data' => $returningRevenueShare->insufficientData,
            ],
            'rfm_distribution' => $this->rfm->getData()['segments'],
            'churn_distribution' => $churnSummary['distribution'],
            'value_at_risk' => $churnSummary['value_at_risk'],
            'cohort_matrix' => $this->cohorts->matrix(),
            'top_affinity' => $this->affinity->top(AffinityLevel::Product),
        ];
    }

    /** @return array{orders_count: int, net_revenue: int, aov: int, customers_new: int, customers_repeat: int} */
    private function periodTotals(string $from, string $to): array
    {
        $row = DB::table('daily_metrics')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(orders_count), 0)::integer AS orders_count,
                COALESCE(SUM(net_revenue), 0)::bigint AS net_revenue,
                COALESCE(SUM(customers_new), 0)::integer AS customers_new,
                COALESCE(SUM(customers_repeat), 0)::integer AS customers_repeat
            ')
            ->first();

        $ordersCount = (int) $row->orders_count;
        $netRevenue = (int) $row->net_revenue;

        return [
            'orders_count' => $ordersCount,
            'net_revenue' => $netRevenue,
            'aov' => $ordersCount > 0 ? intdiv($netRevenue, $ordersCount) : 0,
            'customers_new' => (int) $row->customers_new,
            'customers_repeat' => (int) $row->customers_repeat,
        ];
    }

    /** @return list<array{date: string, jalali_date: string, orders_count: int, net_revenue: int, aov: int}> */
    private function trend(string $from, string $to): array
    {
        $rows = DB::table('daily_metrics')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get(self::TREND_KEEP_ROWS)
            ->map(fn (object $row): array => [
                'date' => (string) $row->date,
                'jalali_date' => (string) $row->jalali_date,
                'orders_count' => (int) $row->orders_count,
                'net_revenue' => (int) $row->net_revenue,
                'aov' => (int) $row->aov,
            ])
            ->all();

        return array_values($rows);
    }
}
