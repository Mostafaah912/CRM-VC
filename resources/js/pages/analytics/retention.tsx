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
import { formatNumber } from '@/lib/format';
import { dashboard } from '@/routes';
import { retention as analyticsRetention } from '@/routes/analytics';
import type { RetentionPageData } from '@/types/analytics';

type Props = {
    data: RetentionPageData;
};

function percentText(value: number | null): string {
    if (value === null) {
        return 'داده کافی نیست';
    }

    return `${formatNumber(Math.round(value * 100))}٪`;
}

export default function RetentionPage({ data }: Props) {
    return (
        <>
            <Head title="نگهداشت مشتری" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">نگهداشت مشتری</h1>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>نرخ خرید مجدد</CardTitle>
                            <CardDescription>
                                مشتریانی با حداقل دو سفارش، از میان مشتریانی با
                                حداقل یک سفارش
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-1">
                            <span className="text-2xl font-semibold">
                                {percentText(data.repeat_purchase_rate.rate)}
                            </span>
                            <span className="text-muted-foreground text-xs">
                                {formatNumber(
                                    data.repeat_purchase_rate.repeat_customers,
                                )}{' '}
                                از{' '}
                                {formatNumber(
                                    data.repeat_purchase_rate
                                        .eligible_customers,
                                )}{' '}
                                مشتری
                            </span>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>سهم درآمد بازگشتی</CardTitle>
                            <CardDescription>
                                سهم درآمدی که بعد از اولین سفارش هر مشتری ثبت
                                شده است
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <span className="text-2xl font-semibold">
                                {percentText(
                                    data.returning_revenue_share.share,
                                )}
                            </span>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>نگهداشت N روزه</CardTitle>
                        <CardDescription>
                            فقط روی کوهورت‌های بالغ محاسبه می‌شود — کوهورت نابالغ
                            «داده کافی نیست» نشان می‌دهد
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>بازه</TableHead>
                                    <TableHead>مشتریان بالغ</TableHead>
                                    <TableHead>بازگشته</TableHead>
                                    <TableHead>نرخ نگهداشت</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.retention.map((window) => (
                                    <TableRow key={window.days}>
                                        <TableCell dir="ltr">
                                            {window.days} روز
                                        </TableCell>
                                        <TableCell>
                                            {formatNumber(
                                                window.mature_customers,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatNumber(
                                                window.returned_customers,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {percentText(window.retention_rate)}
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

RetentionPage.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'نگهداشت مشتری', href: analyticsRetention() },
    ],
};
