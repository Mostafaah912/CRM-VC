import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef } from 'react';
import { StatusBadge, ToneBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { isPageOf, useCursorList } from '@/hooks/use-cursor-list';
import { orderStatuses } from '@/lib/customer-labels';
import { formatNumber, formatToman } from '@/lib/format';
import { orders, products } from '@/routes/customers';
import type { CursorPage, OrderTabRow, ProductTabRow } from '@/types/customers';

export type TabId = 'orders' | 'products';

type Props = {
    customerId: number;
    /** null until the user picks a tab: nothing is fetched before that. */
    active: TabId | null;
    /** Tabs opened at least once stay mounted (hidden), so switching back does not re-fetch. */
    opened: TabId[];
    onSelect: (tab: TabId) => void;
};

const TABS: { id: TabId; label: string }[] = [
    { id: 'orders', label: 'سفارش‌ها' },
    { id: 'products', label: 'محصولات' },
];

const MESSAGES = {
    hint: 'برای دیدن فهرست کامل، یکی از تب‌ها را انتخاب کنید.',
    loading: 'در حال بارگذاری…',
    loadMore: 'بارگذاری بیشتر',
    retry: 'تلاش دوباره',
    noOrders: 'این مشتری سفارشی ندارد.',
    noProducts: 'هنوز محصولی خریداری نشده است.',
};

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function isOrderRow(row: unknown): row is OrderTabRow {
    return (
        isObject(row) &&
        typeof row.woo_order_id === 'number' &&
        typeof row.status === 'string' &&
        typeof row.total === 'number' &&
        typeof row.is_realized === 'boolean' &&
        typeof row.ordered_at_jalali === 'string' &&
        typeof row.ordered_at_iso === 'string'
    );
}

function isProductRow(row: unknown): row is ProductTabRow {
    return (
        isObject(row) &&
        typeof row.name === 'string' &&
        (row.sku === null || typeof row.sku === 'string') &&
        typeof row.total_qty === 'number' &&
        typeof row.order_count === 'number' &&
        typeof row.last_ordered_at_jalali === 'string' &&
        typeof row.last_ordered_at_iso === 'string'
    );
}

const isOrdersPage = (body: unknown): body is CursorPage<OrderTabRow> =>
    isPageOf(body, isOrderRow);
const isProductsPage = (body: unknown): body is CursorPage<ProductTabRow> =>
    isPageOf(body, isProductRow);
const orderKey = (row: OrderTabRow) => row.woo_order_id;
const productKey = (row: ProductTabRow) => row.name;

type FooterProps = {
    status:
        | { kind: 'idle' }
        | { kind: 'loading' }
        | { kind: 'error'; message: string };
    hasMore: boolean;
    onMore: () => void;
    onRetry: () => void;
    loaded: boolean;
};

/** The state under a list: a first-load spinner, an error with a retry, and the load-more button. */
function ListFooter({ status, hasMore, onMore, onRetry, loaded }: FooterProps) {
    return (
        <div className="flex flex-col gap-3">
            {status.kind === 'loading' && !loaded && (
                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                    <Loader2
                        aria-hidden="true"
                        className="size-4 animate-spin"
                    />
                    {MESSAGES.loading}
                </p>
            )}

            {status.kind === 'error' && (
                <div className="flex items-center gap-3">
                    <p role="alert" className="text-destructive text-sm">
                        {status.message}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onRetry}
                    >
                        {MESSAGES.retry}
                    </Button>
                </div>
            )}

            {hasMore && (
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    disabled={status.kind === 'loading'}
                    onClick={onMore}
                >
                    {status.kind === 'loading' && loaded ? (
                        <>
                            <Loader2
                                aria-hidden="true"
                                className="size-4 animate-spin"
                            />
                            {MESSAGES.loading}
                        </>
                    ) : (
                        MESSAGES.loadMore
                    )}
                </Button>
            )}
        </div>
    );
}

function OrdersPanel({ customerId }: { customerId: number }) {
    const urlFor = useCallback(
        (cursor: string | null) =>
            orders.url(
                customerId,
                cursor === null ? {} : { query: { cursor } },
            ),
        [customerId],
    );
    const list = useCursorList(urlFor, isOrdersPage, orderKey);
    const { loadFirst } = list;
    const started = useRef(false);

    // The panel is mounted by the FIRST click on its tab, so this is the lazy load. The ref keeps a dev double-mount from asking twice.
    useEffect(() => {
        if (!started.current) {
            started.current = true;
            void loadFirst();
        }
    }, [loadFirst]);

    return (
        <div className="flex flex-col gap-3">
            {list.total !== null && (
                <p className="text-muted-foreground text-sm">
                    {formatNumber(list.total)} سفارش
                </p>
            )}
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>شماره سفارش</TableHead>
                        <TableHead>تاریخ</TableHead>
                        <TableHead>وضعیت</TableHead>
                        <TableHead>مبلغ</TableHead>
                        <TableHead>محقق‌شده</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {list.loaded && list.items.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={5}
                                className="text-muted-foreground text-center"
                            >
                                {MESSAGES.noOrders}
                            </TableCell>
                        </TableRow>
                    )}
                    {list.items.map((order) => (
                        <TableRow key={order.woo_order_id}>
                            <TableCell className="text-sm">
                                <span dir="ltr">{order.woo_order_id}</span>
                            </TableCell>
                            <TableCell className="text-sm">
                                <time dateTime={order.ordered_at_iso} dir="ltr">
                                    {order.ordered_at_jalali}
                                </time>
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
                            <TableCell>
                                {order.is_realized && (
                                    <ToneBadge tone="success">
                                        محقق‌شده
                                    </ToneBadge>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <ListFooter
                status={list.status}
                loaded={list.loaded}
                hasMore={list.nextCursor !== null}
                onMore={() => void list.loadMore()}
                onRetry={() =>
                    void (list.loaded ? list.loadMore() : list.loadFirst())
                }
            />
        </div>
    );
}

function ProductsPanel({ customerId }: { customerId: number }) {
    const urlFor = useCallback(
        (cursor: string | null) =>
            products.url(
                customerId,
                cursor === null ? {} : { query: { cursor } },
            ),
        [customerId],
    );
    const list = useCursorList(urlFor, isProductsPage, productKey);
    const { loadFirst } = list;
    const started = useRef(false);

    useEffect(() => {
        if (!started.current) {
            started.current = true;
            void loadFirst();
        }
    }, [loadFirst]);

    return (
        <div className="flex flex-col gap-3">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>محصول</TableHead>
                        <TableHead>SKU</TableHead>
                        <TableHead>تعداد کل خرید</TableHead>
                        <TableHead>تعداد سفارش</TableHead>
                        <TableHead>آخرین خرید</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {list.loaded && list.items.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={5}
                                className="text-muted-foreground text-center"
                            >
                                {MESSAGES.noProducts}
                            </TableCell>
                        </TableRow>
                    )}
                    {list.items.map((product) => (
                        <TableRow key={product.name}>
                            <TableCell className="text-sm font-medium">
                                {product.name}
                            </TableCell>
                            <TableCell className="text-sm">
                                {product.sku === null ? (
                                    '—'
                                ) : (
                                    <span dir="ltr">{product.sku}</span>
                                )}
                            </TableCell>
                            <TableCell className="text-sm">
                                {formatNumber(product.total_qty)}
                            </TableCell>
                            <TableCell className="text-sm">
                                {formatNumber(product.order_count)}
                            </TableCell>
                            <TableCell className="text-sm">
                                <time
                                    dateTime={product.last_ordered_at_iso}
                                    dir="ltr"
                                >
                                    {product.last_ordered_at_jalali}
                                </time>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <ListFooter
                status={list.status}
                loaded={list.loaded}
                hasMore={list.nextCursor !== null}
                onMore={() => void list.loadMore()}
                onRetry={() =>
                    void (list.loaded ? list.loadMore() : list.loadFirst())
                }
            />
        </div>
    );
}

/**
 * The Orders / Products tabs of Customer 360. Nothing is fetched until a tab is chosen: the panel of a tab is MOUNTED by its first
 * selection and fetches its first page then; a tab opened once stays mounted (hidden) so going back shows what was loaded. Each panel
 * pages with the server's cursor ("بارگذاری بیشتر"), with plain fetch — not Inertia.
 */
export function CustomerTabs({ customerId, active, opened, onSelect }: Props) {
    return (
        <div className="flex flex-col gap-4">
            <div
                role="tablist"
                aria-label="سفارش‌ها و محصولات"
                className="flex gap-2 border-b"
            >
                {TABS.map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        role="tab"
                        id={`customer-tab-${tab.id}`}
                        aria-selected={active === tab.id}
                        aria-controls={`customer-panel-${tab.id}`}
                        className={
                            active === tab.id
                                ? 'border-primary -mb-px border-b-2 px-3 py-2 text-sm font-medium'
                                : 'text-muted-foreground hover:text-foreground px-3 py-2 text-sm'
                        }
                        onClick={() => onSelect(tab.id)}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {active === null && (
                <p className="text-muted-foreground text-sm">{MESSAGES.hint}</p>
            )}

            {TABS.filter((tab) => opened.includes(tab.id)).map((tab) => (
                <div
                    key={tab.id}
                    role="tabpanel"
                    id={`customer-panel-${tab.id}`}
                    aria-labelledby={`customer-tab-${tab.id}`}
                    hidden={active !== tab.id}
                >
                    {tab.id === 'orders' ? (
                        <OrdersPanel customerId={customerId} />
                    ) : (
                        <ProductsPanel customerId={customerId} />
                    )}
                </div>
            ))}
        </div>
    );
}
