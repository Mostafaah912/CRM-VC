import { Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { PhoneRevealButton } from '@/components/customers/PhoneRevealButton';
import { StatusBadge } from '@/components/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { orderStatuses } from '@/lib/customer-labels';
import { formatToman } from '@/lib/format';
import { dashboard } from '@/routes';
import { index as ordersIndex } from '@/routes/orders';
import type { OrderDetail } from '@/types/orders';

type Props = {
    order: OrderDetail;
};

function Ltr({ children }: { children: React.ReactNode }) {
    return <span dir="ltr">{children}</span>;
}

export default function OrderShow({ order }: Props) {
    const can = useCan();

    return (
        <>
            <Head title={`سفارش ${order.woo_order_id}`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <header className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-xl border p-4">
                    <div className="flex items-start justify-between gap-4">
                        <h1 className="text-2xl font-semibold">
                            سفارش <Ltr>{order.woo_order_id}</Ltr>
                        </h1>
                        <Link
                            href={ordersIndex()}
                            className="text-muted-foreground hover:text-foreground text-sm underline-offset-4 hover:underline"
                        >
                            بازگشت به لیست
                        </Link>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <StatusBadge
                            status={order.status}
                            labels={orderStatuses}
                        />
                        <span className="text-muted-foreground">
                            <Ltr>{order.ordered_at_jalali}</Ltr>
                        </span>
                        <span className="font-medium">
                            {formatToman(order.total)}
                        </span>
                    </div>
                </header>

                {order.needs_phone_review && (
                    <div
                        role="alert"
                        className="flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
                    >
                        <AlertTriangle aria-hidden="true" className="size-4" />
                        این سفارش شماره تلفن قابل‌استفاده نداشت و نیاز به بررسی
                        دارد.
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>اقلام سفارش</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>محصول</TableHead>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>تعداد</TableHead>
                                    <TableHead>قیمت واحد</TableHead>
                                    <TableHead>جمع</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {order.items.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="text-muted-foreground text-center"
                                        >
                                            این سفارش قلمی ندارد.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {order.items.map((item, index) => (
                                    <TableRow key={`${item.name}-${index}`}>
                                        <TableCell className="text-sm font-medium">
                                            {item.name}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {item.sku === null ? (
                                                '—'
                                            ) : (
                                                <Ltr>{item.sku}</Ltr>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            <Ltr>{item.qty}</Ltr>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatToman(item.unit_price)}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatToman(item.line_total)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>مشتری</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {order.customer === null ? (
                            <p className="text-muted-foreground text-sm">
                                سفارش بدون مشتری شناسایی‌شده
                            </p>
                        ) : (
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                <Link
                                    href={order.customer.profile_url}
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {order.customer.display_name ?? 'بدون نام'}
                                </Link>
                                <PhoneRevealButton
                                    customerId={order.customer.id}
                                    maskedPhone={order.customer.phone_masked}
                                    hasPermission={can(
                                        'customers',
                                        'view_full_phone',
                                    )}
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

OrderShow.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'سفارش‌ها', href: ordersIndex() },
    ],
};
