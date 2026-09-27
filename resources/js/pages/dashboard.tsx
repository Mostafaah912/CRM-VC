import { Head, Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { formatNumber, formatToman } from '@/lib/format';
import { churnLevels, rfmSegments } from '@/lib/customer-labels';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as segmentsIndex } from '@/routes/segments';
import { health as systemHealth } from '@/routes/system';
import type { DashboardData, DashboardFilters } from '@/types/dashboard';

type Props = {
    data: DashboardData;
    filters: DashboardFilters;
};

const EMPTY = '—';

const RFM_ORDER: (keyof DashboardData['rfm_distribution'])[] = [
    'champion',
    'loyal',
    'promising',
    'new_customer',
    'at_risk',
    'cant_lose',
    'hibernating',
    'lost',
    'none',
];

const CHURN_ORDER: (keyof DashboardData['churn_distribution'])[] = [
    'low',
    'medium',
    'high',
    'lost',
    'none',
];

function percentText(value: number | null): string {
    if (value === null) {
        return 'داده کافی نیست';
    }

    return `${formatNumber(Math.round(value * 100))}٪`;
}

/** A signed percentage change vs the previous period; null when the previous period was zero (no baseline). */
function delta(current: number, previous: number): string {
    if (previous === 0) {
        return current === 0 ? '—' : 'داده مقایسه‌ای موجود نیست';
    }

    const change = Math.round(((current - previous) / previous) * 100);
    const sign = change > 0 ? '+' : '';

    return `${sign}${formatNumber(change)}٪ نسبت به دوره قبل`;
}

function KpiCard({
    title,
    value,
    compare,
}: {
    title: string;
    value: string;
    compare: string;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border p-3">
            <span className="text-muted-foreground text-xs">{title}</span>
            <span className="text-lg font-medium">{value}</span>
            <span className="text-muted-foreground text-xs">{compare}</span>
        </div>
    );
}

export default function Dashboard({ data, filters }: Props) {
    const can = useCan();
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const messages = Object.values(errors ?? {});

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            dashboard.url({
                query: {
                    from: from.trim() === '' ? undefined : from.trim(),
                    to: to.trim() === '' ? undefined : to.trim(),
                },
            }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const reset = () => {
        setFrom('');
        setTo('');
        router.get(
            dashboard.url(),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="داشبورد" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <h1 className="text-xl font-medium">داشبورد</h1>
                    <span className="text-muted-foreground text-sm" dir="ltr">
                        {data.period.from} .. {data.period.to}
                    </span>
                </div>

                {messages.length > 0 && (
                    <div className="border-destructive/50 text-destructive rounded-lg border p-3 text-sm">
                        {messages.map((message) => (
                            <p key={message}>{message}</p>
                        ))}
                    </div>
                )}

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-4 rounded-xl border p-4"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="period-from">از (شمسی)</Label>
                        <Input
                            id="period-from"
                            dir="ltr"
                            className="w-40"
                            placeholder="1405/01/01"
                            value={from}
                            onChange={(event) => setFrom(event.target.value)}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="period-to">تا (شمسی)</Label>
                        <Input
                            id="period-to"
                            dir="ltr"
                            className="w-40"
                            placeholder="1405/06/29"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                        />
                    </div>
                    <Button type="submit">اعمال بازه</Button>
                    <Button type="button" variant="outline" onClick={reset}>
                        بازه پیش‌فرض (۳۰ روز اخیر)
                    </Button>
                </form>

                <Card>
                    <CardHeader>
                        <CardTitle>خلاصه دوره</CardTitle>
                        <CardDescription>
                            در مقایسه با دوره‌ی هم‌طول قبلی (
                            {data.period.previous_from} ..{' '}
                            {data.period.previous_to})
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                        <KpiCard
                            title="سفارش‌ها"
                            value={formatNumber(data.current.orders_count)}
                            compare={delta(
                                data.current.orders_count,
                                data.previous.orders_count,
                            )}
                        />
                        <KpiCard
                            title="درآمد خالص"
                            value={formatToman(data.current.net_revenue)}
                            compare={delta(
                                data.current.net_revenue,
                                data.previous.net_revenue,
                            )}
                        />
                        <KpiCard
                            title="میانگین ارزش سفارش (AOV)"
                            value={formatToman(data.current.aov)}
                            compare={delta(data.current.aov, data.previous.aov)}
                        />
                        <KpiCard
                            title="مشتریان جدید"
                            value={formatNumber(data.current.customers_new)}
                            compare={delta(
                                data.current.customers_new,
                                data.previous.customers_new,
                            )}
                        />
                        <KpiCard
                            title="مشتریان بازگشتی"
                            value={formatNumber(data.current.customers_repeat)}
                            compare={delta(
                                data.current.customers_repeat,
                                data.previous.customers_repeat,
                            )}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>روند روزانه</CardTitle>
                        <CardDescription>
                            سفارش و درآمد خالص هر روز در بازه انتخابی
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>تاریخ</TableHead>
                                    <TableHead>سفارش‌ها</TableHead>
                                    <TableHead>درآمد خالص</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.trend.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={3}
                                            className="text-muted-foreground text-center"
                                        >
                                            هنوز داده‌ای برای این بازه محاسبه
                                            نشده است.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {data.trend.map((day) => (
                                    <TableRow key={day.date}>
                                        <TableCell dir="ltr">
                                            {day.jalali_date}
                                        </TableCell>
                                        <TableCell>
                                            {formatNumber(day.orders_count)}
                                        </TableCell>
                                        <TableCell>
                                            {formatToman(day.net_revenue)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>نرخ خرید مجدد</CardTitle>
                            <CardDescription>
                                کل عمر فروشگاه — تحت تأثیر فیلتر بازه نیست
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <span className="text-2xl font-semibold">
                                {percentText(data.repeat_purchase_rate.rate)}
                            </span>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>سهم درآمد بازگشتی</CardTitle>
                            <CardDescription>
                                کل عمر فروشگاه — تحت تأثیر فیلتر بازه نیست
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <span className="text-2xl font-semibold">
                                {percentText(data.returning_revenue_share.rate)}
                            </span>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>توزیع RFM</CardTitle>
                        <CardDescription>
                            وضعیت فعلی مشتریان — تحت تأثیر فیلتر بازه نیست
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                        {RFM_ORDER.map((segment) => (
                            <div
                                key={segment}
                                className="flex flex-col gap-1 rounded-lg border p-3"
                            >
                                <span className="text-muted-foreground text-xs">
                                    {segment === 'none'
                                        ? 'بدون امتیاز'
                                        : (rfmSegments[segment] ?? segment)}
                                </span>
                                <span className="text-lg font-medium">
                                    {formatNumber(
                                        data.rfm_distribution[segment],
                                    )}
                                </span>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>توزیع ریسک ریزش و ارزش در خطر</CardTitle>
                        <CardDescription>
                            وضعیت فعلی مشتریان — تحت تأثیر فیلتر بازه نیست
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                            {CHURN_ORDER.map((level) => (
                                <div
                                    key={level}
                                    className="flex flex-col gap-1 rounded-lg border p-3"
                                >
                                    <span className="text-muted-foreground text-xs">
                                        {level === 'none'
                                            ? 'بدون امتیاز'
                                            : (churnLevels[level]?.label ??
                                              level)}
                                    </span>
                                    <span className="text-lg font-medium">
                                        {formatNumber(
                                            data.churn_distribution[level],
                                        )}
                                    </span>
                                </div>
                            ))}
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                ارزش در خطر (مشتریان ریسک بالا/ازدست‌رفته)
                            </span>
                            <span className="text-lg font-medium">
                                {formatToman(data.value_at_risk)}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>ماتریس کوهورت</CardTitle>
                        <CardDescription>
                            نرخ بازگشت هر کوهورت در هر دوره — دوره‌ی نابالغ
                            خاکستری نمایش داده می‌شود
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>کوهورت</TableHead>
                                    <TableHead>اندازه</TableHead>
                                    {data.cohort_matrix[0]?.periods.map(
                                        (period) => (
                                            <TableHead
                                                key={period.period_number}
                                                className="text-center"
                                            >
                                                {formatNumber(
                                                    period.period_number,
                                                )}
                                            </TableHead>
                                        ),
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.cohort_matrix.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={2}
                                            className="text-muted-foreground text-center"
                                        >
                                            هنوز کوهورتی محاسبه نشده است.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {data.cohort_matrix.map((row) => (
                                    <TableRow key={row.cohort_month}>
                                        <TableCell dir="ltr">
                                            {row.cohort_month}
                                        </TableCell>
                                        <TableCell>
                                            {formatNumber(row.cohort_size)}
                                        </TableCell>
                                        {row.periods.map((period) => (
                                            <TableCell
                                                key={period.period_number}
                                                className={
                                                    period.is_mature
                                                        ? 'text-center'
                                                        : 'text-muted-foreground text-center'
                                                }
                                            >
                                                {period.retention_rate === null
                                                    ? EMPTY
                                                    : percentText(
                                                          period.retention_rate,
                                                      )}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>برترین هم‌خریدها (سطح محصول)</CardTitle>
                        <CardDescription>
                            وضعیت فعلی — تحت تأثیر فیلتر بازه نیست
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>محصول A</TableHead>
                                    <TableHead>محصول B</TableHead>
                                    <TableHead>هم‌خرید</TableHead>
                                    <TableHead>Lift</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.top_affinity.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="text-muted-foreground text-center"
                                        >
                                            هنوز هم‌خریدی با اطمینان کافی یافت
                                            نشده است.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {data.top_affinity.map((pair) => (
                                    <TableRow
                                        key={`${pair.entity_a_id}-${pair.entity_b_id}`}
                                    >
                                        <TableCell dir="ltr">
                                            #{formatNumber(pair.entity_a_id)}
                                        </TableCell>
                                        <TableCell dir="ltr">
                                            #{formatNumber(pair.entity_b_id)}
                                        </TableCell>
                                        <TableCell>
                                            {formatNumber(pair.co_customers)}
                                        </TableCell>
                                        <TableCell dir="ltr">
                                            {pair.lift.toFixed(2)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2">
                    {can('segments', 'view') && (
                        <Link
                            href={segmentsIndex()}
                            className="border-sidebar-border/70 dark:border-sidebar-border hover:bg-accent flex flex-col gap-1 rounded-xl border p-4"
                        >
                            <span className="font-medium">سگمنت‌های فعال</span>
                            <span className="text-muted-foreground text-sm">
                                مشاهده فهرست کامل سگمنت‌ها
                            </span>
                        </Link>
                    )}
                    {can('system', 'view') && (
                        <Link
                            href={systemHealth()}
                            className="border-sidebar-border/70 dark:border-sidebar-border hover:bg-accent flex flex-col gap-1 rounded-xl border p-4"
                        >
                            <span className="font-medium">سلامت سیستم</span>
                            <span className="text-muted-foreground text-sm">
                                مشاهده وضعیت Sync و صف‌ها
                            </span>
                        </Link>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'داشبورد', href: dashboard() }],
};
