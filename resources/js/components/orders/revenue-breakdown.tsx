import { formatToman } from '@/lib/format';

type Props = {
    subtotal: number;
    discount_total: number;
    shipping_total: number;
    tax_total: number;
    total: number;
    refunded_total: number;
    net_revenue: number;
    className?: string;
};

/**
 * The one revenue breakdown every order-money display in the app shares (P6-14 phase 3): «مبلغ کالا
 * (پس از تخفیف)» = subtotal − discount_total, «پست» = shipping_total, «مالیات» only when this order's
 * own tax_total is nonzero (most orders have none), «جمع کل» = total, «عودتی (کل)» = refunded_total,
 * «خالص (شامل پست)» = net_revenue. Every figure is its own stored column, shown as-is — never forced
 * to sum to «جمع کل» (a rare order has an uncaptured Woo fee_lines charge; CLAUDE.md §2 money rules
 * mean that gap is shown honestly, not hidden in a padded "سایر" line).
 */
export function RevenueBreakdown({
    subtotal,
    discount_total,
    shipping_total,
    tax_total,
    total,
    refunded_total,
    net_revenue,
    className,
}: Props) {
    const rows: Array<{ label: string; value: number; emphasis?: boolean }> =
        [
            { label: 'مبلغ کالا (پس از تخفیف)', value: subtotal - discount_total },
            { label: 'پست', value: shipping_total },
            ...(tax_total !== 0
                ? [{ label: 'مالیات', value: tax_total }]
                : []),
            { label: 'جمع کل', value: total, emphasis: true },
            { label: 'عودتی (کل)', value: refunded_total },
            { label: 'خالص (شامل پست)', value: net_revenue, emphasis: true },
        ];

    return (
        <dl className={className}>
            {rows.map((row) => (
                <div
                    key={row.label}
                    className="flex items-center justify-between py-1 text-sm"
                >
                    <dt
                        className={
                            row.emphasis
                                ? 'font-medium'
                                : 'text-muted-foreground'
                        }
                    >
                        {row.label}
                    </dt>
                    <dd className={row.emphasis ? 'font-medium' : undefined}>
                        {formatToman(row.value)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
