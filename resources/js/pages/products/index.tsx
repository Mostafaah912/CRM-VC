import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
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
import { productStatuses } from '@/lib/customer-labels';
import { formatNumber, formatToman } from '@/lib/format';
import { dashboard } from '@/routes';
import { index as productsIndex } from '@/routes/products';
import type {
    ProductListFilters,
    ProductListOptions,
    ProductListRow,
} from '@/types/products';
import type { Paginated } from '@/types/system';

type Props = {
    products: Paginated<ProductListRow>;
    filters: ProductListFilters;
    options: ProductListOptions;
};

const ALL = 'all';

type FormState = {
    name: string;
    sku: string;
    status: string;
};

function initialState(filters: ProductListFilters): FormState {
    return {
        name: filters.name ?? '',
        sku: filters.sku ?? '',
        status: filters.status ?? ALL,
    };
}

/** Only what was actually chosen goes into the address, so a clean page has a clean URL. */
function toQuery(state: FormState): Record<string, string> {
    const query: Record<string, string> = {};
    const text: [string, string][] = [
        ['name', state.name.trim()],
        ['sku', state.sku.trim()],
    ];

    for (const [key, value] of text) {
        if (value !== '') {
            query[key] = value;
        }
    }

    if (state.status !== ALL) {
        query.status = state.status;
    }

    return query;
}

export default function ProductsIndex({ products, filters, options }: Props) {
    const [state, setState] = useState<FormState>(initialState(filters));
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const messages = Object.values(errors ?? {});

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setState((current) => ({ ...current, [key]: value }));

    const go = (next: FormState) =>
        router.get(
            productsIndex.url({ query: toQuery(next) }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        go(state);
    };

    const reset = () => {
        const cleared = initialState({ name: null, sku: null, status: null });

        setState(cleared);
        go(cleared);
    };

    return (
        <>
            <Head title="محصولات" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <h1 className="text-xl font-medium">محصولات</h1>
                    <span className="text-muted-foreground text-sm">
                        {products.total} محصول
                    </span>
                </div>

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4"
                >
                    <div className="flex flex-wrap items-end gap-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="product-name">نام محصول</Label>
                            <Input
                                id="product-name"
                                type="search"
                                className="w-56"
                                value={state.name}
                                onChange={(event) =>
                                    set('name', event.target.value)
                                }
                                placeholder="مثلاً پیراهن کتان"
                            />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="product-sku">SKU</Label>
                            <Input
                                id="product-sku"
                                type="search"
                                dir="ltr"
                                className="w-40"
                                value={state.sku}
                                onChange={(event) =>
                                    set('sku', event.target.value)
                                }
                                placeholder="مثلاً HM-01"
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
                                            {productStatuses[status]?.label ??
                                                status}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
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
                                <TableHead>نام محصول</TableHead>
                                <TableHead>SKU</TableHead>
                                <TableHead>وضعیت</TableHead>
                                <TableHead>تعداد فروخته‌شده</TableHead>
                                <TableHead>درآمد</TableHead>
                                <TableHead>تعداد سفارش</TableHead>
                                <TableHead>آخرین فروش</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="text-muted-foreground text-center"
                                    >
                                        محصولی با این شرایط پیدا نشد.
                                    </TableCell>
                                </TableRow>
                            )}
                            {products.data.map((product) => (
                                <TableRow key={product.id}>
                                    <TableCell className="text-sm font-medium">
                                        {product.admin_url === null ? (
                                            product.name
                                        ) : (
                                            <a
                                                href={product.admin_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {product.name}
                                            </a>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {product.sku === null ? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        ) : (
                                            <span dir="ltr">{product.sku}</span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={product.status}
                                            labels={productStatuses}
                                        />
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {formatNumber(product.total_qty_sold)}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {formatToman(product.total_revenue)}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {formatNumber(product.order_count)}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {product.last_sold_at_jalali ===
                                        null ? (
                                            <span className="text-muted-foreground">
                                                هنوز فروش نداشته
                                            </span>
                                        ) : (
                                            <span dir="ltr">
                                                {product.last_sold_at_jalali}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    currentPage={products.current_page}
                    lastPage={products.last_page}
                    prevUrl={products.prev_page_url}
                    nextUrl={products.next_page_url}
                />
            </div>
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'محصولات', href: productsIndex() },
    ],
};
