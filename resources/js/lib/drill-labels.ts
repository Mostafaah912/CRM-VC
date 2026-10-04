/**
 * P6-12: Persian labels for DrillDialog's generic column headers. The JSON drill endpoint
 * (DrillService::rows()) keeps English keys on purpose — DrillDialog uses them as `row[column]` data
 * keys, not just display text — so the translation happens here, once, at display time. Money columns
 * name the unit (CLAUDE.md §2: Toman everywhere); mirrors DrillService::COLUMN_LABELS on the backend
 * (the CSV export's own header row), kept in sync by hand since the two run in different languages.
 */
const DRILL_COLUMN_LABELS: Record<string, string> = {
    customer_id: 'شناسه مشتری',
    order_id: 'شماره سفارش',
    ordered_at: 'تاریخ سفارش',
    total: 'مبلغ کل (تومان)',
    net_revenue: 'درآمد خالص (تومان)',
    product_revenue: 'مبلغ کالا پس از تخفیف (تومان)',
    shipping_revenue: 'پست (تومان)',
    tax_total: 'مالیات (تومان)',
    refunded_total: 'عودتی کل (تومان)',
    first_order_at: 'تاریخ اولین سفارش',
    total_revenue: 'مجموع درآمد (تومان)',
    rfm_score: 'امتیاز RFM',
    recency_days: 'روزهای اخیر',
    churn_risk_score: 'امتیاز ریسک ریزش',
    clv_estimated: 'ارزش طول عمر تخمینی (تومان)',
    clv_historical: 'ارزش طول عمر تاریخی (تومان)',
    orders_in_period: 'تعداد سفارش در این دوره',
    revenue_in_period: 'درآمد این دوره (تومان)',
};

export function drillColumnLabel(column: string): string {
    return DRILL_COLUMN_LABELS[column] ?? column;
}

/** An id column is shown as a plain number — never grouped with a thousands separator. */
export function isDrillIdColumn(column: string): boolean {
    return column === 'id' || column.endsWith('_id');
}
