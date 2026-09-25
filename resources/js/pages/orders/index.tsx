import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Pagination } from '@/components/pagination';
import { StatusBadge, ToneBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { orderStatuses } from '@/lib/customer-labels';
import { formatToman } from '@/lib/format';
import { dashboard } from '@/routes';
import { show as customerShow } from '@/routes/customers';
import { index as ordersIndex, show as orderShow } from '@/routes/orders';
import type {
    OrderListFilters,
    OrderListOptions,
    OrderListRow,
} from '@/types/orders';
import type { Paginated } from '@/types/system';

type Props = {
    orders: Paginated<OrderListRow>;
    filters: OrderListFilters;
    options: OrderListOptions;
};

const ALL = 'all';

type FormState = {
    woo_order_id: string;
    status: string;
    is_realized: string;
    needs_phone_review: boolean;
    ordered_from: string;
    ordered_to: string;
};

function initialState(filters: OrderListFilters): FormState {
    return {
        woo_order_id:
            filters.woo_order_id === null ? '' : String(filters.woo_order_id),
        status: filters.status ?? ALL,
        is_realized:
            filters.is_realized === null ? ALL : String(filters.is_realized),
        needs_phone_review: filters.needs_phone_review === true,
        ordered_from: filters.ordered_from ?? '',
        ordered_to: filters.ordered_to ?? '',
    };
}

/** Only what was actually chosen goes into the address, so a clean page has a clean URL. */
function toQuery(state: FormState): Record<string, string> {
    const query: Record<string, string> = {};
    const text: [string, string][] = [
        ['woo_order_id', state.woo_order_id.trim()],
        ['ordered_from', state.ordered_from.trim()],
        ['ordered_to', state.ordered_to.trim()],
    ];

    for (const [key, value] of text) {
        if (value !== '') {
            query[key] = value;
        }
    }

    if (state.status !== ALL) {
        query.status = state.status;
    }

    if (state.is_realized !== ALL) {
        query.is_realized = state.is_realized;
    }

    if (state.needs_phone_review) {
        query.needs_phone_review = '1';
    }

    return query;
}

export default function OrdersIndex({ orders, filters, options }: Props) {
    const [state, setState] = useState<FormState>(initialState(filters));
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const messages = Object.values(errors ?? {});

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setState((current) => ({ ...current, [key]: value }));

    const go = (next: FormState) =>
        router.get(
            ordersIndex.url({ query: toQuery(next) }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        go(state);
    };

    const reset = () => {
        const cleared = initialState({
            woo_order_id: null,
            status: null,
            is_realized: null,
            needs_phone_review: null,
            ordered_from: null,
            ordered_to: null,
        });

        setState(cleared);
        go(cleared);
    };

    return (
        <>
            <Head title="سفارش‌ها" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <h1 className="text-xl font-medium">سفارش‌ها</h1>
                    <span className="text-muted-foreground text-sm">
                        {orders.total} سفارش
                    </span>
                </div>

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4"
                >
                    <div className="flex flex-wrap items-end gap-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="woo-order-id">
                                شماره سفارش (WooCommerce)
                            </Label>
                            <Input
                                id="woo-order-id"
                                type="search"
                                inputMode="numeric"
                                dir="ltr"
                                className="w-40"
                                value={state.woo_order_id}
                                onChange={(event) =>
                                    set('woo_order_id', event.target.value)
                                }
                                placeholder="مثلاً 12345"
                            />
                        </div>

                        <div className="flex min-w-40 flex-col gap-1.5">
                            <Label>وضعیت</Label>
                            <Select
                                value={state.status}
                                onValueChange={(value) => set('status', value)}
                            >
                                <SelectTrigger aria-label="وضعیت">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>همه</SelectItem>
                                    {options.statuses.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {orderStatuses[status]?.label ??
                                                status}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex min-w-40 flex-col gap-1.5">
                            <Label>محقق‌شده</Label>
                            <Select
                                value={state.is_realized}
                                onValueChange={(value) =>
                                    set('is_realized', value)
                                }
                            >
                                <SelectTrigger aria-label="محقق‌شده">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>همه</SelectItem>
                                    <SelectItem value="true">بله</SelectItem>
                                    <SelectItem value="false">خیر</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex items-center gap-2 pb-2">
                            <Checkbox
                                id="needs-phone-review"
                                checked={state.needs_phone_review}
                                onCheckedChange={(checked) =>
                                    set('needs_phone_review', checked === true)
                                }
                            />
                            <Label htmlFor="needs-phone-review">
                                فقط نیازمند بررسی تلفن
                            </Label>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-end gap-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="ordered-from">
                                تاریخ ثبت از (شمسی)
                            </Label>
                            <Input
                                id="ordered-from"
                                dir="ltr"
                                className="w-40"
                                placeholder="1405/01/01"
                                value={state.ordered_from}
                                onChange={(event) =>
                                    set('ordered_from', event.target.value)
                                }
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="ordered-to">
                                تاریخ ثبت تا (شمسی)
                            </Label>
                            <Input
                                id="ordered-to"
                                dir="ltr"
                                className="w-40"
                                placeholder="1405/06/29"
                                value={state.ordered_to}
                                onChange={(event) =>
                                    set('ordered_to', event.target.value)
                                }
                            />
                        </div>
                        <div className="ms-auto flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={reset}
                            >
                                پاک‌کردن
                            </Button>
                            <Button type="submit">اعمال</Button>
                        </div>
                    </div>

                    {messages.length > 0 && (
                        <ul
                            role="alert"
                            className="text-destructive list-inside list-disc text-sm"
                        >
                            {messages.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    )}
                </form>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>شماره سفارش</TableHead>
                                <TableHead>تاریخ</TableHead>
                                <TableHead>وضعیت</TableHead>
                                <TableHead>مبلغ</TableHead>
                                <TableHead>مشتری</TableHead>
                                <TableHead>بررسی تلفن</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground text-center"
                                    >
                                        سفارشی با این شرایط پیدا نشد.
                                    </TableCell>
                                </TableRow>
                            )}
                            {orders.data.map((order) => (
                                <TableRow key={order.id}>
                                    <TableCell className="text-sm">
                                        <Link
                                            href={orderShow(order.id)}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            <span dir="ltr">
                                                {order.woo_order_id}
                                            </span>
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        <span dir="ltr">
                                            {order.ordered_at_jalali}
                                        </span>
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
                                    <TableCell className="text-sm">
                                        {order.customer_id === null ? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        ) : (
                                            <Link
                                                href={customerShow(
                                                    order.customer_id,
                                                )}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {order.customer_display_name ??
                                                    '—'}
                                            </Link>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {order.needs_phone_review && (
                                            <ToneBadge tone="warning">
                                                نیاز به بررسی
                                            </ToneBadge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    currentPage={orders.current_page}
                    lastPage={orders.last_page}
                    prevUrl={orders.prev_page_url}
                    nextUrl={orders.next_page_url}
                />
            </div>
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'سفارش‌ها', href: ordersIndex() },
    ],
};
