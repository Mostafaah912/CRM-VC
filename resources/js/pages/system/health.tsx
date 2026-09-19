import { Head } from '@inertiajs/react';
import { StatusBadge, ToneBadge } from '@/components/status-badge';
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
import { reconciliationStatuses } from '@/lib/system-status';
import { dashboard } from '@/routes';
import { health } from '@/routes/system';
import type {
    GateOne,
    HealthLastSync,
    HealthMonth,
    HealthQueue,
} from '@/types/system';

type Props = {
    last_sync: HealthLastSync | null;
    queue: HealthQueue;
    reconciliation: HealthMonth[];
    gate_one: GateOne;
};

const UNKNOWN = 'نامشخص';

function Ltr({ children }: { children: React.ReactNode }) {
    return <span dir="ltr">{children}</span>;
}

function Metric({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="text-muted-foreground text-sm">{label}</dt>
            <dd className="text-sm font-medium">{value}</dd>
        </div>
    );
}

function percent(value: string | null): string {
    return value === null ? '—' : `${value}٪`;
}

function GatePanel({ gate }: { gate: GateOne }) {
    return (
        <Card
            className={
                gate.passed ? 'border-emerald-500/40' : 'border-amber-500/40'
            }
        >
            <CardHeader>
                <div className="flex items-center justify-between gap-4">
                    <CardTitle>دروازه ۱: تطبیق سفارش‌ها با ووکامرس</CardTitle>
                    {gate.passed ? (
                        <ToneBadge tone="success">عبور کرد</ToneBadge>
                    ) : (
                        <ToneBadge tone="warning">باز</ToneBadge>
                    )}
                </div>
                <CardDescription>
                    هر ماه از مهر ۱۴۰۳ تا آخرین ماه کامل باید سبز باشد: اختلاف
                    تعداد سفارش صفر و اختلاف درآمد کمتر از ۱٪.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-sm">
                    <Ltr>
                        {gate.green_months} / {gate.total_months}
                    </Ltr>{' '}
                    ماه سبز
                </p>

                {gate.failing_months.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">
                            ماه‌های قرمز یا ناموفق
                        </h3>
                        <ul className="space-y-1">
                            {gate.failing_months.map((month) => (
                                <li
                                    key={month.month}
                                    className="flex items-center gap-3 text-sm"
                                >
                                    <Ltr>{month.month}</Ltr>
                                    <StatusBadge
                                        status={month.status}
                                        labels={reconciliationStatuses}
                                    />
                                    {month.count_diff !== null && (
                                        <span className="text-muted-foreground">
                                            اختلاف تعداد:{' '}
                                            <Ltr>{month.count_diff}</Ltr>، اختلاف
                                            درآمد:{' '}
                                            <Ltr>
                                                {percent(month.diff_percent)}
                                            </Ltr>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {gate.missing_months.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">
                            ماه‌های تطبیق‌نشده ({gate.missing_months.length})
                        </h3>
                        <ul className="flex flex-wrap gap-2">
                            {gate.missing_months.map((month) => (
                                <li
                                    key={month}
                                    className="bg-muted text-muted-foreground rounded-md px-2 py-0.5 text-xs"
                                >
                                    <Ltr>{month}</Ltr>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function Health({
    last_sync,
    queue,
    reconciliation,
    gate_one,
}: Props) {
    return (
        <>
            <Head title="سلامت سیستم" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">سلامت سیستم</h1>

                <GatePanel gate={gate_one} />

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>آخرین همگام‌سازی موفق</CardTitle>
                            <CardDescription>سفارش‌ها</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {last_sync === null ? (
                                <p className="text-muted-foreground text-sm">
                                    هنوز همگام‌سازی موفقی ثبت نشده است.
                                </p>
                            ) : (
                                <dl className="space-y-2">
                                    <Metric
                                        label="شروع"
                                        value={
                                            <Ltr>{last_sync.started_at}</Ltr>
                                        }
                                    />
                                    <Metric
                                        label="پایان"
                                        value={
                                            last_sync.finished_at === null ? (
                                                '—'
                                            ) : (
                                                <Ltr>
                                                    {last_sync.finished_at}
                                                </Ltr>
                                            )
                                        }
                                    />
                                    <Metric
                                        label="مدت"
                                        value={
                                            last_sync.duration_seconds === null
                                                ? '—'
                                                : `${last_sync.duration_seconds} ثانیه`
                                        }
                                    />
                                    <Metric
                                        label="صفحه‌های خوانده‌شده"
                                        value={last_sync.pages_processed}
                                    />
                                    <Metric
                                        label="سفارش‌های همگام‌شده"
                                        value={last_sync.records_processed}
                                    />
                                </dl>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>صف</CardTitle>
                            <CardDescription>
                                کارهای منتظر و کارهای شکست‌خورده
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2">
                                <Metric
                                    label="کارهای منتظر در صف همگام‌سازی"
                                    value={queue.sync_depth ?? UNKNOWN}
                                />
                                <Metric
                                    label="کارهای شکست‌خورده"
                                    value={queue.failed_jobs ?? UNKNOWN}
                                />
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>تطبیق ماهانه</CardTitle>
                        <CardDescription>سه ماه آخر</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ماه</TableHead>
                                    <TableHead>وضعیت</TableHead>
                                    <TableHead>اختلاف تعداد</TableHead>
                                    <TableHead>اختلاف درآمد</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reconciliation.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="text-muted-foreground text-center"
                                        >
                                            هنوز هیچ ماهی تطبیق داده نشده است.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {reconciliation.map((month) => (
                                    <TableRow key={month.month}>
                                        <TableCell>
                                            <Ltr>{month.month}</Ltr>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={month.status}
                                                labels={reconciliationStatuses}
                                            />
                                            {month.error !== null && (
                                                <p className="text-muted-foreground mt-1 max-w-prose text-xs break-words">
                                                    {month.error}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {month.count_diff === null ? (
                                                '—'
                                            ) : (
                                                <Ltr>{month.count_diff}</Ltr>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Ltr>
                                                {percent(month.diff_percent)}
                                            </Ltr>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Health.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'سلامت سیستم', href: health() },
    ],
};
