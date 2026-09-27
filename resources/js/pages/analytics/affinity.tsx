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
import { formatNumber } from '@/lib/format';
import { dashboard } from '@/routes';
import { affinity as analyticsAffinity } from '@/routes/analytics';
import type { AffinityLevel, AffinityPageData } from '@/types/analytics';
import type { DashboardAffinityPair } from '@/types/dashboard';

type Props = {
    data: AffinityPageData;
};

const LEVELS: { key: AffinityLevel; title: string; description: string }[] = [
    {
        key: 'category',
        title: 'سطح دسته‌بندی',
        description: 'قوی‌ترین سیگنال Cross-Sell — حداقل هم‌خرید: ۲۰',
    },
    {
        key: 'product',
        title: 'سطح محصول',
        description: 'پیشنهاد محصول مکمل — حداقل هم‌خرید: ۱۰',
    },
    {
        key: 'variation',
        title: 'سطح تنوع (Variation)',
        description: 'Reorder همان SKU — حداقل هم‌خرید: ۵',
    },
    {
        key: 'basket',
        title: 'سطح سبد خرید',
        description:
            'با هم خریده می‌شوند (در یک سفارش) — حداقل هم‌خرید: ۱۰. با سبد کوچک، نمونه کافی ندارد؛ این سطح فعلاً قابل‌کلیک نیست.',
    },
];

function PairsTable({
    level,
    pairs,
}: {
    level: AffinityLevel;
    pairs: DashboardAffinityPair[];
}) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>موجودیت A</TableHead>
                    <TableHead>موجودیت B</TableHead>
                    <TableHead>هم‌خرید</TableHead>
                    <TableHead>Lift</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {pairs.length === 0 && (
                    <TableRow>
                        <TableCell
                            colSpan={4}
                            className="text-muted-foreground text-center"
                        >
                            هنوز جفتی با اطمینان کافی یافت نشده است.
                        </TableCell>
                    </TableRow>
                )}
                {pairs.map((pair) => {
                    const row = (
                        <>
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
                        </>
                    );

                    if (level === 'basket') {
                        return (
                            <TableRow
                                key={`${pair.entity_a_id}-${pair.entity_b_id}`}
                            >
                                {row}
                            </TableRow>
                        );
                    }

                    return (
                        <DrillDialog
                            key={`${pair.entity_a_id}-${pair.entity_b_id}`}
                            widget="affinity_pair"
                            params={{
                                affinity_level: level,
                                entity_a_id: String(pair.entity_a_id),
                                entity_b_id: String(pair.entity_b_id),
                            }}
                            title={`هم‌خرید #${pair.entity_a_id} و #${pair.entity_b_id}`}
                            trigger={
                                <TableRow className="cursor-pointer">
                                    {row}
                                </TableRow>
                            }
                        />
                    );
                })}
            </TableBody>
        </Table>
    );
}

export default function AffinityPage({ data }: Props) {
    return (
        <>
            <Head title="هم‌خرید محصولات" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">هم‌خرید محصولات</h1>

                {LEVELS.map(({ key, title, description }) => (
                    <Card key={key}>
                        <CardHeader>
                            <CardTitle>{title}</CardTitle>
                            <CardDescription>{description}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <PairsTable level={key} pairs={data[key]} />
                        </CardContent>
                    </Card>
                ))}
            </div>
        </>
    );
}

AffinityPage.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'هم‌خرید محصولات', href: analyticsAffinity() },
    ],
};
