/** Props of the P6-18 phase 5 metrics guide page (/metrics/guide, PRD §12-§18). Every field here is a
 * live number read by `MetricsGuideService::guide()` from the same source the real Calculators use —
 * never a second hardcoded copy. */

import type { RetentionPageData } from '@/types/analytics';

export type RfmScoreRange = {
    score: number;
    min: number;
    max: number;
    customers: number;
};

export type RfmSegmentGuide = {
    segment: string;
    condition: string;
    customers: number;
};

export type SystemSegmentGuide = {
    name: string;
    sentence: string;
    members: number;
};

export type RfmGuide = {
    r_scores: RfmScoreRange[];
    f_scores: RfmScoreRange[];
    m_scores: RfmScoreRange[];
    segments: RfmSegmentGuide[];
    not_eligible_customers: number;
    eligible_total: number;
    system_segments: SystemSegmentGuide[];
};

export type ClvConfidenceDistribution = {
    low: number;
    medium: number;
    high: number;
};

export type ClvGuide = {
    margin_rate: number;
    horizon_years: number;
    min_orders_for_estimate: number;
    low_confidence_threshold: number;
    high_confidence_threshold: number;
    confidence_distribution: ClvConfidenceDistribution;
    historical_customers: number;
    estimated_customers: number;
};

export type ChurnThresholds = {
    p50: number;
    p75: number;
    p90: number;
    sample_size: number;
    is_fallback: boolean;
};

export type ChurnLevelGuide = {
    level: string;
    customers: number;
};

export type ChurnGuide = {
    thresholds: ChurnThresholds;
    low_sample_guard: {
        minimum_sample: number;
        fallback: { p50: number; p75: number; p90: number };
    };
    levels: ChurnLevelGuide[];
    no_orders_customers: number;
};

export type LifecycleStageGuide = {
    stage: string;
    customers: number;
};

export type LifecycleGuide = {
    thresholds: ChurnThresholds;
    stages: LifecycleStageGuide[];
};

export type CohortGuide = {
    cohort_months: number;
    mature_cells: number;
    immature_cells: number;
    total_customers: number;
    retention: RetentionPageData;
};

export type AffinityMinCoCustomers = {
    category: number;
    product: number;
    variation: number;
    basket: number;
};

export type AffinityPairsStored = {
    category: number;
    product: number;
    variation: number;
    basket: number;
};

export type AffinityGuide = {
    min_co_customers: AffinityMinCoCustomers;
    pairs_stored: AffinityPairsStored;
    /** A 0..1 ratio — pass to formatRate()/formatPercent(), never pre-multiplied. */
    order_items_resolved_percent: number | null;
};

export type DashboardPointerGuide = {
    note: string;
};

export type DataQualityGuide = {
    /** A 0..1 ratio — pass to formatRate()/formatPercent(), never pre-multiplied. */
    order_items_resolved_percent: number | null;
    open_identity_conflicts: number;
    last_metrics_run_at: string | null;
    last_metrics_run_at_iso: string | null;
    churn_threshold_sample_size: number;
    churn_threshold_is_fallback: boolean;
};

export type MetricsGuideData = {
    rfm: RfmGuide;
    clv: ClvGuide;
    churn: ChurnGuide;
    lifecycle: LifecycleGuide;
    cohort: CohortGuide;
    affinity: AffinityGuide;
    dashboard: DashboardPointerGuide;
    data_quality: DataQualityGuide;
};
