import { Head, Link } from '@inertiajs/react';
import { CustomerTimeline } from '@/components/customers/CustomerTimeline';
import { PhoneRevealButton } from '@/components/customers/PhoneRevealButton';
import { StatusBadge } from '@/components/status-badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import {
    churnLevels,
    clvConfidences,
    customerStatuses,
    lifecycleStages,
    orderStatuses,
} from '@/lib/customer-labels';
import { formatNumber, formatToman } from '@/lib/format';
import { dashboard } from '@/routes';
import {
    index as customersIndex,
    show as customersShow,
} from '@/routes/customers';
import type {
    CustomerProfile,
    CustomerProfileMetrics,
} from '@/types/customers';

type Props = {
    profile: CustomerProfile;
};

const EMPTY = '—';

function Ltr({ children }: { children: React.ReactNode }) {
    return <span dir="ltr">{children}</span>;
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-lg">{value}</CardTitle>
            </CardHeader>
        </Card>
    );
}

function Score({ label, value }: { label: string; value: number | null }) {
    return (
        <div className="flex flex-col items-center gap-1">
            <span className="text-muted-foreground text-xs">{label}</span>
            <span className="text-lg font-medium">
                {value === null ? EMPTY : formatNumber(value)}
            </span>
        </div>
    );
}

function hasRiskSection(metrics: CustomerProfileMetrics): boolean {
    return (
        metrics.r_score !== null ||
        metrics.f_score !== null ||
        metrics.m_score !== null ||
        metrics.churn_risk_level !== null ||
        metrics.clv_estimated !== null
    );
}

export default function CustomerShow({ profile }: Props) {
    const {
        customer,
        metrics,
        recent_orders,
        orders_total,
        recent_products,
        timeline,
    } = profile;
    const can = useCan();
    const place = [customer.province, customer.city]
        .filter((part): part is string => part !== null && part !== '')
        .join('، ');

    return (
        <>
            <Head title={customer.display_name ?? 'مشتری'} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <header className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-xl border p-4">
                    <div className="flex items-start justify-between gap-4">
                        <h1 className="text-2xl font-semibold">
                            {customer.display_name ?? 'بدون نام'}
                        </h1>
                        <Link
                            href={customersIndex()}
                            className="text-muted-foreground hover:text-foreground text-sm underline-offset-4 hover:underline"
                        >
                            بازگشت به لیست
                        </Link>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <PhoneRevealButton
                            customerId={customer.id}
                            maskedPhone={customer.phone}
                            hasPermission={can('customers', 'view_full_phone')}
                        />
                        <StatusBadge
                            status={customer.status}
                            labels={customerStatuses}
                        />
                        <StatusBadge
                            status={customer.lifecycle_stage}
                            labels={lifecycleStages}
                        />
                        {place !== '' && (
                            <span className="text-muted-foreground">
                                {place}
                            </span>
                        )}
                        {customer.first_seen_at !== null && (
                            <span className="text-muted-foreground">
                                اولین مشاهده:{' '}
                                <Ltr>{customer.first_seen_at}</Ltr>
                            </span>
                        )}
                    </div>
                </header>

                {metrics !== null && (
                    <section
                        aria-label="معیارها"
                        className="grid grid-cols-2 gap-4 lg:grid-cols-4"
                    >
                        <Stat
                            label="مجموع خرید"
                            value={formatToman(metrics.total_revenue)}
                        />
                        <Stat
                            label="تعداد سفارش‌ها"
                            value={formatNumber(metrics.total_orders)}
                        />
                        <Stat
                            label="میانگین سبد"
                            value={formatToman(metrics.aov)}
                        />
                        <Stat
                            label="آخرین خرید"
                            value={
                                metrics.last_order_at === null ? (
                                    EMPTY
                                ) : (
                                    <Ltr>{metrics.last_order_at}</Ltr>
                                )
                            }
                        />
                    </section>
                )}

                {metrics !== null && hasRiskSection(metrics) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>RFM و ریسک ریزش</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="flex flex-wrap items-center gap-8">
                                <div className="flex gap-6">
                                    <Score
                                        label="تازگی (R)"
                                        value={metrics.r_score}
                                    />
                                    <Score
                                        label="تکرار (F)"
                                        value={metrics.f_score}
                                    />
                                    <Score
                                        label="ارزش (M)"
                                        value={metrics.m_score}
                                    />
                                </div>

                                {metrics.churn_risk_level !== null && (
                                    <div className="flex items-center gap-2">
                                        <StatusBadge
                                            status={metrics.churn_risk_level}
                                            labels={churnLevels}
                                        />
                                        {metrics.churn_risk_score !== null && (
                                            <span className="text-muted-foreground text-sm">
                                                امتیاز ریسک:{' '}
                                                <Ltr>
                                                    {metrics.churn_risk_score}
                                                </Ltr>
                                            </span>
                                        )}
                                    </div>
                                )}

                                {metrics.clv_estimated !== null && (
                                    <div className="flex flex-col">
                                        <span className="text-muted-foreground text-xs">
                                            ارزش طول عمر (CLV) برآوردی
                                        </span>
                                        <span className="text-lg font-medium">
                                            {formatToman(metrics.clv_estimated)}
                                        </span>
                                        {metrics.clv_confidence !== null && (
                                            <span className="text-muted-foreground text-xs">
                                                {clvConfidences[
                                                    metrics.clv_confidence
                                                ] ?? metrics.clv_confidence}
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>

                            {metrics.churn_reason !== null &&
                                metrics.churn_reason !== '' && (
                                    <p className="text-muted-foreground text-sm">
                                        {metrics.churn_reason}
                                    </p>
                                )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex flex-row items-baseline justify-between gap-4">
                        <CardTitle>آخرین سفارش‌ها</CardTitle>
                        <Link
                            href={customersShow(customer.id)}
                            className="text-muted-foreground hover:text-foreground text-sm underline-offset-4 hover:underline"
                        >
                            مشاهده همه سفارش‌ها ({formatNumber(orders_total)})
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>شماره سفارش</TableHead>
                                    <TableHead>تاریخ</TableHead>
                                    <TableHead>وضعیت</TableHead>
                                    <TableHead>مبلغ</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recent_orders.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="text-muted-foreground text-center"
                                        >
                                            این مشتری سفارشی ندارد.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {recent_orders.map((order) => (
                                    <TableRow key={order.woo_order_id}>
                                        <TableCell className="text-sm">
                                            <Ltr>{order.woo_order_id}</Ltr>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            <Ltr>{order.ordered_at}</Ltr>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={order.status}
                                                labels={orderStatuses}
                                            />
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatToman(order.total)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>آخرین محصولات خریداری‌شده</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>محصول</TableHead>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>تعداد دفعات خرید</TableHead>
                                    <TableHead>آخرین خرید</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recent_products.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="text-muted-foreground text-center"
                                        >
                                            هنوز محصولی خریداری نشده است.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {recent_products.map((product) => (
                                    <TableRow
                                        key={`${product.name}|${product.sku ?? ''}|${product.last_purchased_at}`}
                                    >
                                        <TableCell className="text-sm font-medium">
                                            {product.name}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {product.sku === null ? (
                                                EMPTY
                                            ) : (
                                                <Ltr>{product.sku}</Ltr>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatNumber(
                                                product.purchase_count,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            <Ltr>
                                                {product.last_purchased_at}
                                            </Ltr>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {timeline.data.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>تاریخچه‌ی رویدادها</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <CustomerTimeline
                                customerId={customer.id}
                                initialData={timeline}
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'مشتریان', href: customersIndex() },
    ],
};
