/** Props of the P6-06 Dashboard page (PRD §18). */

export type DashboardPeriodTotals = {
    orders_count: number;
    net_revenue: number;
    aov: number;
    customers_new: number;
    customers_repeat: number;
};

export type DashboardTrendDay = {
    date: string;
    jalali_date: string;
    orders_count: number;
    net_revenue: number;
    aov: number;
};

export type DashboardRateResult = {
    rate: number | null;
    insufficient_data: boolean;
};

/** Every rfm_segment (PRD §12) plus 'none' for a not-yet-eligible customer. */
export type DashboardRfmDistribution = {
    champion: number;
    loyal: number;
    promising: number;
    new_customer: number;
    at_risk: number;
    cant_lose: number;
    hibernating: number;
    lost: number;
    none: number;
};

/** Every churn_risk_level plus 'none' for a not-yet-scored customer. */
export type DashboardChurnDistribution = {
    low: number;
    medium: number;
    high: number;
    lost: number;
    none: number;
};

export type DashboardCohortPeriod = {
    period_number: number;
    retention_rate: number | null;
    is_mature: boolean;
    active_customers: number;
};

export type DashboardCohortRow = {
    cohort_month: string;
    cohort_size: number;
    periods: DashboardCohortPeriod[];
};

export type DashboardAffinityPair = {
    entity_a_id: number;
    entity_b_id: number;
    entity_a_name: string | null;
    entity_b_name: string | null;
    co_customers: number;
    support: number;
    confidence: number;
    lift: number;
    level: string;
};

export type DashboardData = {
    period: {
        from: string;
        to: string;
        previous_from: string;
        previous_to: string;
        from_jalali: string;
        to_jalali: string;
        previous_from_jalali: string;
        previous_to_jalali: string;
    };
    current: DashboardPeriodTotals;
    previous: DashboardPeriodTotals;
    trend: DashboardTrendDay[];
    repeat_purchase_rate: DashboardRateResult;
    returning_revenue_share: DashboardRateResult;
    rfm_distribution: DashboardRfmDistribution;
    churn_distribution: DashboardChurnDistribution;
    value_at_risk: number;
    cohort_matrix: DashboardCohortRow[];
    top_affinity: DashboardAffinityPair[];
};

export type DashboardFilters = {
    from: string | null;
    to: string | null;
};
