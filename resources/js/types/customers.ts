/** Props of the P3-01 customer list. The phone is already masked (or not) by the server; the page never decides that. */

export type CustomerRow = {
    id: number;
    display_name: string | null;
    /** Always the masked form; the full number comes only from the audited reveal endpoint. */
    phone: string;
    status: string;
    lifecycle_stage: string;
    province: string | null;
    city: string | null;
    first_seen_at: string | null;
    needs_review: boolean;
};

export type CustomerFilters = {
    search: string | null;
    status: string | null;
    lifecycle_stage: string | null;
    province: string | null;
    city: string | null;
    needs_review: boolean | null;
    first_seen_from: string | null;
    first_seen_to: string | null;
};

export type CustomerOptions = {
    statuses: string[];
    lifecycle_stages: string[];
    provinces: string[];
    cities: string[];
};

/** Props of the P3-03 Customer 360 page. The only contact detail is the phone, in its masked form, for every viewer. */

export type CustomerProfileHeader = {
    id: number;
    display_name: string | null;
    phone: string;
    status: string;
    lifecycle_stage: string;
    province: string | null;
    city: string | null;
    first_seen_at: string | null;
};

export type CustomerProfileMetrics = {
    /** Money is int Toman. */
    total_orders: number;
    total_revenue: number;
    aov: number;
    first_order_at: string | null;
    last_order_at: string | null;
    r_score: number | null;
    f_score: number | null;
    m_score: number | null;
    /** null when the customer has fewer than two orders: never 0, and shown together with clv_confidence. */
    clv_estimated: number | null;
    clv_confidence: string | null;
    /** numeric(5,2) on a 0..100 scale, as an exact decimal string. */
    churn_risk_score: string | null;
    churn_risk_level: string | null;
    churn_reason: string | null;
};

export type CustomerProfileOrder = {
    woo_order_id: number;
    number: string | null;
    status: string;
    total: number;
    ordered_at: string;
};

export type CustomerProfileProduct = {
    name: string;
    sku: string | null;
    purchase_count: number;
    last_purchased_at: string;
};

export type CustomerProfile = {
    customer: CustomerProfileHeader;
    /** null when no customer_metrics row exists: the page then shows no metrics section at all. */
    metrics: CustomerProfileMetrics | null;
    recent_orders: CustomerProfileOrder[];
    orders_total: number;
    recent_products: CustomerProfileProduct[];
    /** The first page of the timeline; the rest comes from GET /customers/{customer}/timeline with next_cursor. */
    timeline: TimelineResponse;
};

/** Only the payload keys the server allowlists can be here, and only plain scalars. */
export type TimelinePayload = Record<string, string | number | boolean | null>;

export type TimelineEvent = {
    id: number;
    event_type: string;
    /** Jalali date and Tehran time. The raw stored value is never sent. */
    happened_at_jalali: string;
    /** ISO 8601 in UTC. */
    happened_at_iso: string;
    payload: TimelinePayload | null;
};

export type TimelineResponse = {
    data: TimelineEvent[];
    /** Opaque: handed back unchanged to fetch the next page; null on the last one. */
    next_cursor: string | null;
    has_more: boolean;
};
