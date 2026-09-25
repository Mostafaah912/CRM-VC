/** Props of the P2-12 system pages. Every value is already formatted by the server (Jalali, Tehran time); the client never sees a cursor, id or credential. */

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type SyncRun = {
    started_at: string;
    entity: string;
    status: string;
    pages_processed: number;
    records_processed: number;
    finished_at: string | null;
    duration_seconds: number | null;
    error: string | null;
};

export type SyncLogFilters = {
    status: string | null;
    entity: string | null;
};

export type SyncLogOptions = {
    statuses: string[];
    entities: string[];
};

export type IdentityConflictRow = {
    created_at: string;
    status: string;
    woo_order_id: number | null;
    reason: string;
};

export type HealthLastSync = {
    started_at: string;
    finished_at: string | null;
    duration_seconds: number | null;
    pages_processed: number;
    records_processed: number;
};

export type HealthQueue = {
    sync_depth: number | null;
    failed_jobs: number | null;
};

export type HealthMonth = {
    month: string;
    status: string;
    count_diff: number | null;
    diff_percent: string | null;
    error: string | null;
};

export type HealthFailingMonth = {
    month: string;
    status: string;
    count_diff: number | null;
    diff_percent: string | null;
};

export type GateOne = {
    passed: boolean;
    total_months: number;
    green_months: number;
    missing_months: string[];
    failing_months: HealthFailingMonth[];
};
