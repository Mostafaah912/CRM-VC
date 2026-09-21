import type { StatusLabels } from '@/lib/system-status';

/** customers.status */
export const customerStatuses: StatusLabels = {
    active: { label: 'فعال', tone: 'success' },
    blocked: { label: 'مسدود', tone: 'danger' },
    anonymized: { label: 'ناشناس‌شده', tone: 'neutral' },
};

/** customers.lifecycle_stage */
export const lifecycleStages: StatusLabels = {
    prospect: { label: 'بالقوه', tone: 'neutral' },
    new: { label: 'جدید', tone: 'success' },
    active: { label: 'فعال', tone: 'success' },
    repeat: { label: 'تکرارشونده', tone: 'success' },
    loyal: { label: 'وفادار', tone: 'success' },
    at_risk: { label: 'در معرض ریزش', tone: 'warning' },
    dormant: { label: 'خفته', tone: 'warning' },
    lost: { label: 'ازدست‌رفته', tone: 'danger' },
};

/** customer_metrics.churn_risk_level — the level the metrics engine stored, never a cut-off applied in the browser. */
export const churnLevels: StatusLabels = {
    low: { label: 'پایدار', tone: 'success' },
    medium: { label: 'ریسک متوسط', tone: 'warning' },
    high: { label: 'ریسک بالا', tone: 'danger' },
    lost: { label: 'ازدست‌رفته', tone: 'danger' },
};

/** customer_metrics.clv_confidence */
export const clvConfidences: Record<string, string> = {
    low: 'اطمینان کم',
    medium: 'اطمینان متوسط',
    high: 'اطمینان بالا',
};

/** orders.status (WooCommerce slugs); any other slug is shown as stored. */
export const orderStatuses: StatusLabels = {
    pending: { label: 'در انتظار پرداخت', tone: 'warning' },
    processing: { label: 'در حال پردازش', tone: 'success' },
    'on-hold': { label: 'در انتظار بررسی', tone: 'warning' },
    completed: { label: 'تکمیل‌شده', tone: 'success' },
    cancelled: { label: 'لغوشده', tone: 'neutral' },
    refunded: { label: 'مسترد‌شده', tone: 'neutral' },
    failed: { label: 'ناموفق', tone: 'danger' },
};

/** products.status (Catalog's own ProductStatus enum) */
export const productStatuses: StatusLabels = {
    publish: { label: 'منتشرشده', tone: 'success' },
    draft: { label: 'پیش‌نویس', tone: 'neutral' },
    pending: { label: 'در انتظار بررسی', tone: 'warning' },
    private: { label: 'خصوصی', tone: 'neutral' },
};
