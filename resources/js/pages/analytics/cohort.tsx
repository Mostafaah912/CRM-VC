import { Head } from '@inertiajs/react';
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
import { DrillDialog } from '@/components/dashboard/drill-dialog';
import { formatCohortMonth, formatNumber, formatRate } from '@/lib/format';
import { dashboard } from '@/routes';
import { cohort as analyticsCohort } from '@/routes/analytics';
import type { CohortPageData } from '@/types/analytics';

type Props = {
    data: CohortPageData;
};

const EMPTY = '—';

export default function CohortPage({ data }: Props) {
    return (
        <>
            <Head title="ماتریس کوهورت" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <h1 className="text-xl font-medium">ماتریس کوهورت</h1>
                    <span className="text-muted-foreground text-sm">
                        {formatNumber(data.length)} کوهورت
                    </span>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>نرخ بازگشت هر کوهورت در هر دوره</CardTitle>
                        <CardDescription>
                            دوره‌ی نابالغ خاکستری نمایش داده می‌شود، نه صفر — هر
                            سلول قابل‌کلیک است و مشتریان پشت آن را نشان می‌دهد
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>کوهورت</TableHead>
                                    <TableHead>اندازه</TableHead>
                                    {data[0]?.periods.map((period) => (
                                        <TableHead
                                            key={period.period_number}
                                            className="text-center"
                                        >
                                            {formatNumber(period.period_number)}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={2}
                                            className="text-muted-foreground text-center"
                                        >
                                            هنوز کوهورتی محاسبه نشده است.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {data.map((row) => (
                                    <TableRow key={row.cohort_month}>
                                        <TableCell dir="ltr">
                                            {formatCohortMonth(
                                                row.cohort_month,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatNumber(row.cohort_size)}
                                        </TableCell>
                                        {row.periods.map((period) => (
                                            <TableCell
                                                key={period.period_number}
                                                className="p-1 text-center"
                                            >
                                                {!period.is_mature ||
                                                period.active_customers ===
                                                    0 ? (
                                                    <span
                                                        className={
                                                            period.is_mature
                                                                ? ''
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        {period.is_mature
                                                            ? EMPTY
                                                            : 'نابالغ'}
                                                    </span>
                                                ) : (
                                                    <DrillDialog
                                                        widget="cohort_period"
                                                        params={{
                                                            cohort_month:
                                                                row.cohort_month,
                                                            period_number:
                                                                String(
                                                                    period.period_number,
                                                                ),
                                                        }}
                                                        title={`کوهورت ${formatCohortMonth(row.cohort_month)} — دوره ${period.period_number}`}
                                                        trigger={
                                                            <button
                                                                type="button"
                                                                className="w-full underline-offset-4 hover:underline"
                                                            >
                                                                {formatRate(
                                                                    period.retention_rate,
                                                                )}
                                                            </button>
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                        ))}
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

CohortPage.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'ماتریس کوهورت', href: analyticsCohort() },
    ],
};
