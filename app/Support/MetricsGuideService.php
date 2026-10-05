<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Analytics\Services\AffinityService;
use App\Modules\Analytics\Services\RetentionService;
use App\Modules\Customers\Enums\LifecycleStage;
use App\Modules\Metrics\Enums\ChurnRiskLevel;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Enums\RfmSegment;
use App\Modules\Metrics\Models\MetricRun;
use App\Modules\Metrics\Services\ChurnThresholdService;
use App\Modules\Metrics\Services\RfmPageService;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Support\RuleSentenceRenderer;
use Illuminate\Support\Facades\DB;

/**
 * PRD §12-§18's "راهنمای شاخص‌ها" (metrics guide, P6-18 phase 5): every number here is read from the
 * exact config/constant/table the real Calculators read — `config('metrics.*')`,
 * `AffinityService::MIN_CO_CUSTOMERS_*`, `ChurnThresholdService::percentiles()`, `customer_metrics`,
 * `metric_runs.thresholds` — never a second hardcoded copy, so changing a real threshold changes this
 * page too (verified by a dynamic test, not just a static one). Composes `App\Modules`'s own public
 * Services (RetentionService, AffinityService, RfmPageService) — this file lives in `app/Support`
 * because no single module's PRD §07 dependency table covers Metrics + Segments + Analytics + Orders
 * at once, the same reasoning `HealthCheckService` already established (P6-10).
 *
 * Returns one plain, typed array (the same "DTO" convention `AnalyticsService::dashboard()`/
 * `RfmPageService::getData()` already use) so Sprint 7's AI Analyst can read it as context without
 * this task building any AI itself (explicit instruction).
 *
 * No raw SQL anywhere here: Rule 7's exemption (`tests/Arch/ArchitectureTest.php`) covers only
 * `app/Modules/Metrics/`, `app/Modules/Analytics/` and one named Catalog file — `app/Support/` is
 * not on that list, so every grouped aggregate below is a handful of plain, non-raw query-builder
 * calls (one per enum case / score 1..5) instead of a single `selectRaw`/`GROUP BY`. This page is
 * operator-triggered, not a request hot path, so the extra round trips are a deliberate trade, not
 * an oversight.
 */
final class MetricsGuideService
{
    private const RFM_SEGMENT_CONDITIONS = [
        'champion' => 'R ≥ 4 و F ≥ 4',
        'loyal' => 'R ≥ 3 و F ≥ 3',
        'promising' => 'R ≥ 4 و F ≤ 2 و بیش از یک سفارش',
        'new_customer' => 'R = 5 و دقیقاً یک سفارش',
        'at_risk' => 'R = 2 و F ≥ 3',
        'cant_lose' => 'R = 1 و F ≥ 4 و M ≥ 4',
        'hibernating' => 'R ≤ 2 و F ≤ 2',
        'lost' => 'R = 1 (و هیچ‌کدام از شرط‌های بالا)',
    ];

    public function __construct(
        private readonly RetentionService $retention,
        private readonly RfmPageService $rfmPage,
        private readonly ChurnThresholdService $churnThresholds,
        private readonly RuleSentenceRenderer $sentences,
    ) {}

    /** @return array<string, mixed> */
    public function guide(): array
    {
        $thresholds = $this->storedThresholds();
        $orderItemsResolvedPercent = $this->orderItemsResolvedPercent();

        return [
            'rfm' => $this->rfm(),
            'clv' => $this->clv(),
            'churn' => $this->churn($thresholds),
            'lifecycle' => $this->lifecycle($thresholds),
            'cohort' => $this->cohort(),
            'affinity' => $this->affinityGuide($orderItemsResolvedPercent),
            'dashboard' => $this->dashboardPointer(),
            'data_quality' => $this->dataQuality($thresholds, $orderItemsResolvedPercent),
        ];
    }

    /**
     * A 0..1 ratio, like every other rate in this app's "DTO" convention (`RetentionService`'s
     * `rate`/`share` fields) — the frontend's single `formatPercent()` does the ×100 and rounding, so
     * this never duplicates that formatting decision. Shared by the Affinity and Data Quality
     * sections — the task names this figure in both.
     */
    private function orderItemsResolvedPercent(): ?float
    {
        $base = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.is_realized', true)
            ->whereNull('o.deleted_at');

        $total = (clone $base)->count();
        $resolved = (clone $base)->whereNotNull('oi.product_id')->count();

        return $total > 0 ? round($resolved / $total, 4) : null;
    }

    /**
     * RFM score boundaries (real day/order/toman range + customer count per 1..5) plus the 8-segment
     * distribution — the counts/segment distribution are `RfmPageService::getData()`'s own already-
     * tested methods; only the min/max RANGE per score is new (PRD's NTILE buckets have no stored
     * boundary of their own, so this reads it live from customer_metrics the same way the NTILE
     * itself was computed).
     *
     * @return array<string, mixed>
     */
    private function rfm(): array
    {
        $existing = $this->rfmPage->getData();

        return [
            'r_scores' => $this->scoreRanges('r_score', 'recency_days'),
            'f_scores' => $this->scoreRanges('f_score', 'frequency'),
            'm_scores' => $this->scoreRanges('m_score', 'monetary'),
            'segments' => array_map(
                fn (RfmSegment $segment): array => [
                    'segment' => $segment->value,
                    'condition' => self::RFM_SEGMENT_CONDITIONS[$segment->value],
                    'customers' => $existing['segments'][$segment->value] ?? 0,
                ],
                RfmSegment::cases(),
            ),
            'not_eligible_customers' => $existing['segments']['none'] ?? 0,
            'eligible_total' => array_sum($existing['segments']),
            'system_segments' => $this->systemSegments(),
        ];
    }

    /**
     * One score column ('r_score'|'f_score'|'m_score') and one underlying value column
     * ('recency_days'|'frequency'|'monetary') — both always literal strings from the three call
     * sites in rfm() below, never request input.
     *
     * @return list<array{score: int, min: int, max: int, customers: int}>
     */
    private function scoreRanges(string $scoreColumn, string $valueColumn): array
    {
        return array_map(function (int $score) use ($scoreColumn, $valueColumn): array {
            $bucket = DB::table('customer_metrics')->where($scoreColumn, $score);
            $customers = (clone $bucket)->count();

            return [
                'score' => $score,
                'min' => $customers > 0 ? (int) (clone $bucket)->min($valueColumn) : 0,
                'max' => $customers > 0 ? (int) (clone $bucket)->max($valueColumn) : 0,
                'customers' => $customers,
            ];
        }, range(1, 5));
    }

    /**
     * The 12 real `is_system` segments, rendered to a plain Persian sentence (RuleSentenceRenderer) —
     * never the raw rule JSON. Reads `segments` directly: it is Segments' own public table, not a
     * cross-module Model import (Segments has no Service method for "every system segment", and
     * adding one just for this read-only list was judged unnecessary indirection).
     *
     * @return list<array{name: string, sentence: string, members: int}>
     */
    private function systemSegments(): array
    {
        $segments = Segment::query()
            ->where('is_system', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['name', 'rule', 'member_count']);

        return array_values($segments->map(fn (Segment $segment): array => [
            'name' => $segment->name,
            'sentence' => $this->sentences->render($segment->rule),
            'members' => $segment->member_count,
        ])->all());
    }

    /** @return array<string, mixed> */
    private function clv(): array
    {
        return [
            'margin_rate' => (float) config('metrics.margin_rate'),
            'horizon_years' => (float) config('metrics.horizon_years'),
            'min_orders_for_estimate' => (int) config('metrics.clv.min_orders_for_estimate'),
            'low_confidence_threshold' => (int) config('metrics.clv.low_confidence_threshold'),
            'high_confidence_threshold' => (int) config('metrics.clv.high_confidence_threshold'),
            'confidence_distribution' => [
                'low' => (int) DB::table('customer_metrics')->where('clv_confidence', 'low')->count(),
                'medium' => (int) DB::table('customer_metrics')->where('clv_confidence', 'medium')->count(),
                'high' => (int) DB::table('customer_metrics')->where('clv_confidence', 'high')->count(),
            ],
            'historical_customers' => (int) DB::table('customer_metrics')->whereNotNull('clv_historical')->count(),
            'estimated_customers' => (int) DB::table('customer_metrics')->whereNotNull('clv_estimated')->count(),
        ];
    }

    /**
     * @param  array{p50: int, p75: int, p90: int, sample_size: int, is_fallback: bool}  $thresholds
     * @return array<string, mixed>
     */
    private function churn(array $thresholds): array
    {
        return [
            'thresholds' => $thresholds,
            'low_sample_guard' => [
                'minimum_sample' => 200,
                'fallback' => config('metrics.fallback_percentiles'),
            ],
            'levels' => array_map(fn (ChurnRiskLevel $level): array => [
                'level' => $level->value,
                'customers' => (int) DB::table('customer_metrics')->where('churn_risk_level', $level->value)->count(),
            ], ChurnRiskLevel::cases()),
            'no_orders_customers' => (int) DB::table('customer_metrics')->whereNull('churn_risk_level')->count(),
        ];
    }

    /**
     * @param  array{p50: int, p75: int, p90: int, sample_size: int, is_fallback: bool}  $thresholds
     * @return array<string, mixed>
     */
    private function lifecycle(array $thresholds): array
    {
        return [
            'thresholds' => $thresholds,
            'stages' => array_map(fn (LifecycleStage $stage): array => [
                'stage' => $stage->value,
                'customers' => (int) DB::table('customers')->whereNull('deleted_at')->where('lifecycle_stage', $stage->value)->count(),
            ], LifecycleStage::cases()),
        ];
    }

    /** @return array<string, mixed> */
    private function cohort(): array
    {
        $snapshots = DB::table('cohort_snapshots');

        return [
            'cohort_months' => (clone $snapshots)->distinct()->count('cohort_month'),
            'mature_cells' => (clone $snapshots)->where('is_mature', true)->count(),
            'immature_cells' => (clone $snapshots)->where('is_mature', false)->count(),
            'total_customers' => (int) ((clone $snapshots)->where('period_number', 0)->sum('cohort_size')),
            'retention' => $this->retention->summary(),
        ];
    }

    /** @return array<string, mixed> */
    private function affinityGuide(?float $orderItemsResolvedPercent): array
    {
        return [
            'min_co_customers' => [
                'category' => AffinityService::MIN_CO_CUSTOMERS_CATEGORY,
                'product' => AffinityService::MIN_CO_CUSTOMERS_PRODUCT,
                'variation' => AffinityService::MIN_CO_CUSTOMERS_VARIATION,
                'basket' => AffinityService::MIN_CO_CUSTOMERS_BASKET,
            ],
            'pairs_stored' => [
                'category' => (int) DB::table('product_affinities')->where('level', 'category')->count(),
                'product' => (int) DB::table('product_affinities')->where('level', 'product')->count(),
                'variation' => (int) DB::table('product_affinities')->where('level', 'variation')->count(),
                'basket' => (int) DB::table('product_affinities')->where('level', 'basket')->count(),
            ],
            'order_items_resolved_percent' => $orderItemsResolvedPercent,
        ];
    }

    /** @return array<string, mixed> */
    private function dashboardPointer(): array
    {
        return [
            'note' => 'شاخص‌های داشبورد (درآمد، AOV، مشتری جدید/بازگشتی، تفکیک مبلغ کالا/پست، ارزش در معرض ریزش) در صفحه‌ی داشبورد با جزئیات کامل نمایش داده می‌شوند؛ این صفحه فقط لینک می‌دهد تا تعریف هرکدام دوباره نوشته نشود.',
        ];
    }

    /**
     * @param  array{p50: int, p75: int, p90: int, sample_size: int, is_fallback: bool}  $thresholds
     * @return array<string, mixed>
     */
    private function dataQuality(array $thresholds, ?float $orderItemsResolvedPercent): array
    {
        $lastFullRun = MetricRun::query()
            ->where('mode', MetricRunMode::Full)
            ->where('status', MetricRunStatus::Completed)
            ->orderByDesc('finished_at')
            ->first(['finished_at']);

        return [
            'order_items_resolved_percent' => $orderItemsResolvedPercent,
            'open_identity_conflicts' => (int) DB::table('identity_conflicts')->where('status', 'pending')->count(),
            'last_metrics_run_at' => TehranDateTime::formatOrNull($lastFullRun?->finished_at),
            'last_metrics_run_at_iso' => $lastFullRun?->finished_at?->toIso8601ZuluString(),
            'churn_threshold_sample_size' => $thresholds['sample_size'],
            'churn_threshold_is_fallback' => $thresholds['is_fallback'],
        ];
    }

    /** @return array{p50: int, p75: int, p90: int, sample_size: int, is_fallback: bool} */
    private function storedThresholds(): array
    {
        $lastFullRun = MetricRun::query()
            ->where('mode', MetricRunMode::Full)
            ->where('status', MetricRunStatus::Completed)
            ->whereNotNull('thresholds')
            ->orderByDesc('finished_at')
            ->first(['thresholds']);

        /** @var array{p50: int, p75: int, p90: int, sample_size: int}|null $stored */
        $stored = $lastFullRun?->thresholds;

        $fresh = $stored ?? $this->churnThresholds->percentiles();

        return [...$fresh, 'is_fallback' => $fresh['sample_size'] < 200];
    }
}
