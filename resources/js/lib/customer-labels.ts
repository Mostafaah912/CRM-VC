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
