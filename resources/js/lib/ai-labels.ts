import type { StatusLabels } from '@/lib/system-status';

export const severityLabels: StatusLabels = {
    info: { label: 'اطلاع', tone: 'neutral' },
    warning: { label: 'هشدار', tone: 'warning' },
    critical: { label: 'بحرانی', tone: 'danger' },
};

export const kindLabels: Record<string, string> = {
    fact: 'واقعیت',
    inference: 'برداشت',
    recommendation: 'پیشنهاد',
};

export const effortLabels: Record<string, string> = {
    low: 'کم',
    medium: 'متوسط',
    high: 'زیاد',
};

export const priorityLabels: StatusLabels = {
    low: { label: 'اولویت کم', tone: 'neutral' },
    medium: { label: 'اولویت متوسط', tone: 'warning' },
    high: { label: 'اولویت بالا', tone: 'danger' },
};

export const confidenceLabels: StatusLabels = {
    low: { label: 'اطمینان کم', tone: 'danger' },
    medium: { label: 'اطمینان متوسط', tone: 'warning' },
    high: { label: 'اطمینان بالا', tone: 'success' },
};

/** Why the system lowered the model's confidence (OutputValidator::capConfidence). */
export const adjustmentReasons: Record<string, string> = {
    clv_confidence_mostly_low:
        'اطمینان محاسبه‌ی ارزش مشتری برای بیشتر مشتریان پایین است',
    anomaly_unexplained: 'یک ناهنجاری بدون توضیح در داده وجود دارد',
    data_quality_warnings: 'هشدار کیفیت داده ثبت شده است',
    ungrounded_numbers: 'عددهایی در متن آمده که در داده وجود ندارند',
};

export const analystTypeLabels: Record<string, string> = {
    daily_brief: 'خلاصه‌ی روزانه',
    weekly_review: 'مرور هفتگی',
};
