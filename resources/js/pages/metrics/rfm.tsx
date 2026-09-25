import { Head, Link } from '@inertiajs/react';
import { rfmSegments } from '@/lib/customer-labels';
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
import { formatNumber, formatToman } from '@/lib/format';
import { dashboard } from '@/routes';
import { show as customerShow } from '@/routes/customers';
import { rfm as metricsRfm } from '@/routes/metrics';
import type { RfmPageData } from '@/types/metrics';

type Props = {
    data: RfmPageData;
};

const EMPTY = '—';
const SCORE_DIMENSIONS: { key: 'r' | 'f' | 'm'; label: string }[] = [
    { key: 'r', label: 'تازگی (R)' },
    { key: 'f', label: 'تکرار (F)' },
    { key: 'm', label: 'ارزش (M)' },
];
const SEGMENT_ORDER: (keyof RfmPageData['segments'])[] = [
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

function percentage(count: number, total: number): string {
    if (total === 0) {
        return '۰٪';
    }

    return `${formatNumber(Math.round((count / total) * 100))}٪`;
}

export default function RfmPage({ data }: Props) {
    const {
        segments,
        scores,
        latest_run: latestRun,
        top_champions: topChampions,
    } = data;
    const totalCustomers = Object.values(segments).reduce((a, b) => a + b, 0);

    return (
        <>
            <Head title="تحلیل RFM" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <header className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-1 rounded-xl border p-4">
                    <h1 className="text-2xl font-semibold">تحلیل RFM</h1>
                    <p className="text-muted-foreground text-sm">
                        {latestRun === null ? (
                            'هنوز هیچ بازمحاسبه‌ای انجام نشده است.'
                        ) : (
                            <>
                                آخرین بازمحاسبه:{' '}
                                <span dir="ltr">
                                    {latestRun.computed_at ?? EMPTY}
                                </span>{' '}
                                (وضعیت: {latestRun.status})
                            </>
                        )}
                    </p>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>توزیع سگمنت‌ها</CardTitle>
                        <CardDescription>
                            {formatNumber(totalCustomers)} مشتری
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                        {SEGMENT_ORDER.map((segment) => (
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
                                    {formatNumber(segments[segment])}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {percentage(
                                        segments[segment],
                                        totalCustomers,
                                    )}
                                </span>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>توزیع امتیازها</CardTitle>
                        <CardDescription>
                            تعداد مشتریان در هر امتیاز، از ۱ تا ۵
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead />
                                    {['۱', '۲', '۳', '۴', '۵'].map((label) => (
                                        <TableHead
                                            key={label}
                                            className="text-center"
                                        >
                                            {label}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {SCORE_DIMENSIONS.map(({ key, label }) => (
                                    <TableRow key={key}>
                                        <TableCell className="font-medium">
                                            {label}
                                        </TableCell>
                                        {['1', '2', '3', '4', '5'].map(
                                            (score) => (
                                                <TableCell
                                                    key={score}
                                                    className="text-center"
                                                >
                                                    {formatNumber(
                                                        scores[key][score] ?? 0,
                                                    )}
                                                </TableCell>
                                            ),
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>مشتریان قهرمان برتر</CardTitle>
                        <CardDescription>
                            بر اساس مجموع خرید، حداکثر ۱۰ مشتری
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>مشتری</TableHead>
                                    <TableHead>امتیاز RFM</TableHead>
                                    <TableHead>مجموع خرید</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {topChampions.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={3}
                                            className="text-muted-foreground text-center"
                                        >
                                            هنوز مشتری قهرمانی وجود ندارد.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {topChampions.map((champion) => (
                                    <TableRow key={champion.customer_id}>
                                        <TableCell className="text-sm">
                                            <Link
                                                href={customerShow(
                                                    champion.customer_id,
                                                )}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                مشاهده پرونده مشتری #
                                                {formatNumber(
                                                    champion.customer_id,
                                                )}
                                            </Link>
                                        </TableCell>
                                        <TableCell
                                            className="text-sm"
                                            dir="ltr"
                                        >
                                            {champion.rfm_score ?? EMPTY}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatToman(
                                                champion.total_revenue,
                                            )}
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

RfmPage.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'تحلیل RFM', href: metricsRfm() },
    ],
};
