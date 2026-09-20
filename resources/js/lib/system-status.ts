export type StatusTone = 'success' | 'warning' | 'danger' | 'neutral';

export type StatusInfo = {
    label: string;
    tone: StatusTone;
};

export type StatusLabels = Record<string, StatusInfo>;

/** sync_jobs.status */
export const syncStatuses: StatusLabels = {
    running: { label: 'در حال اجرا', tone: 'warning' },
    completed: { label: 'موفق', tone: 'success' },
    failed: { label: 'ناموفق', tone: 'danger' },
    partial: { label: 'ناقص', tone: 'warning' },
};

/** reconciliation_reports.status */
export const reconciliationStatuses: StatusLabels = {
    green: { label: 'سبز', tone: 'success' },
    red: { label: 'قرمز', tone: 'danger' },
    failed: { label: 'ناموفق', tone: 'danger' },
};

/** identity_conflicts.status */
export const conflictStatuses: StatusLabels = {
    pending: { label: 'در انتظار بررسی', tone: 'warning' },
    confirmed_same: { label: 'تأیید: همان مشتری', tone: 'success' },
    confirmed_different: { label: 'تأیید: مشتری متفاوت', tone: 'neutral' },
    ignored: { label: 'نادیده گرفته شد', tone: 'neutral' },
};

/** identity_conflicts.reason codes this build knows how to word; any other code is shown as stored. */
export const conflictReasons: Record<string, string> = {
    last_name_mismatch: 'نام خانوادگی با مشتری موجود هم‌خوان نیست',
    no_phone: 'سفارش شماره‌ی تلفن قابل‌استفاده ندارد',
};

export const syncEntities: Record<string, string> = {
    orders: 'سفارش‌ها',
};

/** A status the server sends that this build does not know is shown as-is, neutral — never hidden. */
export function statusInfo(labels: StatusLabels, status: string): StatusInfo {
    return labels[status] ?? { label: status, tone: 'neutral' };
}
