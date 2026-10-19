export type AnalystType = 'daily_brief' | 'weekly_review';

export type KeyMetric = { label: string; value: string; change: string };
export type InsightItem = { text: string; kind: string; severity: string };
export type RiskItem = { text: string; severity: string };
export type OpportunityItem = { text: string };
export type RecommendationItem = {
    action: string;
    rationale: string;
    effort: string;
    priority: string;
};
export type AnomalyItem = { interpretation: string };

export type ConfidenceAdjustment = {
    from: string;
    to: string;
    reasons: string[];
};

/** Cost, tokens and model — present only for a holder of ai.view_cost. */
export type TechnicalInfo = {
    provider: string;
    model: string;
    prompt_version: string;
    input_tokens: number;
    output_tokens: number;
    cost_usd: string;
    duration_ms: number | null;
};

export type AnalystInsight = {
    id: number;
    type: string;
    scope: string | null;
    period: { start: string | null; end: string | null };
    created_at: string;
    manual: boolean;
    outdated: boolean;
    confidence: string;
    confidence_adjustment: ConfidenceAdjustment | null;
    ungrounded_numbers: string[];
    headline: string;
    summary: string;
    key_metrics: KeyMetric[];
    insights: InsightItem[];
    risks: RiskItem[];
    opportunities: OpportunityItem[];
    recommendations: RecommendationItem[];
    anomalies_explained: AnomalyItem[];
    data_quality_note: string;
    data_gaps: string[];
    technical?: TechnicalInfo;
};

export type GenerationState = {
    status: 'idle' | 'queued' | 'running' | 'done' | 'failed';
    insight_id?: number;
    from_cache?: boolean;
    code?: string;
    message?: string;
    already_running?: boolean;
};

export type BudgetInfo = {
    month: string;
    used_usd: string;
    cap_usd: string;
    percent_usd: number;
    used_tokens: number;
    cap_tokens: number | null;
    percent_tokens: number | null;
    requests: number;
    state: 'ok' | 'warn' | 'blocked';
    last_usage_date: string | null;
};

export type AnalystPageData = {
    enabled: boolean;
    readiness: { ready: boolean; code: string | null; message: string | null };
    latest: Record<AnalystType, AnalystInsight | null>;
    generation: Record<AnalystType, GenerationState>;
    freshness: {
        stale: boolean;
        data_age_days: number | null;
        as_of: string | null;
    };
    last_run: { type: string; at: string; manual: boolean } | null;
    recent_problem: {
        type: string;
        reason: string;
        message: string;
        at: string;
    } | null;
    definitions: { realized_statuses: { slug: string; label: string }[] };
    manual: {
        max_per_window: number;
        window_minutes: number;
        remaining: number;
    };
    budget?: BudgetInfo;
};

export type HistoryPage = {
    data: AnalystInsight[];
    page: number;
    per_page: number;
    total: number;
    last_page: number;
};

/** GET /ai/analyst/widget — the dashboard widget (short form, no cost fields). */
export type AnalystWidgetData = {
    enabled: boolean;
    readiness: { ready: boolean; message: string | null };
    latest: {
        id: number;
        headline: string;
        confidence: string;
        confidence_reduced: boolean;
        period: { start: string | null; end: string | null };
        created_at: string;
        outdated: boolean;
    } | null;
    freshness: {
        stale: boolean;
        orders_sync_behind: boolean;
        orders_behind_hours: number | null;
        as_of: string | null;
    };
    recent_problem: {
        type: string;
        reason: string;
        message: string;
        at: string;
    } | null;
};

/** GET /customers/{id}/analyst-panel — aggregates only. null = the viewer lacks that permission. */
export type CustomerAnalystPanel = {
    customer_id: number;
    segments:
        | {
              id: number;
              name: string;
              type: string;
              members: number;
              avg_recency_days: number | null;
              avg_monetary: number | null;
          }[]
        | null;
    latest_insight: {
        id: number;
        type: string;
        headline: string;
        created_at: string;
    } | null;
};
