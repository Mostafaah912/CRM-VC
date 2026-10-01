import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { drillColumnLabel, isDrillIdColumn } from '@/lib/drill-labels';
import { formatNumber } from '@/lib/format';

type DrillResponse = {
    columns: string[];
    rows: Record<string, string | number | null>[];
    truncated: boolean;
};

type Props = {
    /** One of DrillService's known widgets (P6-07): orders, customers_new, customers_repeat, rfm_segment, churn_level. */
    widget: string;
    /** Extra query params the widget needs (e.g. { segment: 'champion' }) — merged with the dashboard's own from/to. */
    params?: Record<string, string>;
    title: string;
    trigger: React.ReactNode;
};

function query(
    widget: string,
    params: Record<string, string> | undefined,
    forExport: boolean,
): string {
    const search = new URLSearchParams(params ?? {});
    const suffix = forExport ? '/export' : '';

    return `/internal/drill/${widget}${suffix}${search.toString() === '' ? '' : `?${search.toString()}`}`;
}

function cell(column: string, value: string | number | null): string {
    if (value === null) {
        return '—';
    }

    if (typeof value !== 'number') {
        return value;
    }

    // An id is shown plain — never grouped with a thousands separator (P6-12).
    return isDrillIdColumn(column) ? String(value) : formatNumber(value);
}

/** P6-07: PRD Sec.18's "هیچ عددی که پشتش دیده نشود قابل اعتماد نیست" — every drillable number opens this. */
export function DrillDialog({ widget, params, title, trigger }: Props) {
    const [data, setData] = useState<DrillResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = () => {
        if (data !== null || loading) {
            return;
        }

        setLoading(true);
        setError(null);

        fetch(query(widget, params, false), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('drill request failed');
                }

                return response.json() as Promise<DrillResponse>;
            })
            .then(setData)
            .catch(() => setError('بارگذاری این جزئیات با خطا مواجه شد.'))
            .finally(() => setLoading(false));
    };

    return (
        <Dialog onOpenChange={(open) => open && load()}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[80vh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>
                    {data?.truncated
                        ? `فقط ${formatNumber(data.rows.length)} ردیف اول نمایش داده می‌شود — برای فهرست کامل، خروجی CSV بگیرید.`
                        : 'ردیف‌های پشت این عدد.'}
                </DialogDescription>

                {loading && (
                    <p className="text-muted-foreground text-sm">
                        در حال بارگذاری…
                    </p>
                )}
                {error !== null && (
                    <p className="text-destructive text-sm">{error}</p>
                )}

                {data !== null && data.rows.length === 0 && (
                    <p className="text-muted-foreground text-sm">
                        ردیفی برای نمایش وجود ندارد.
                    </p>
                )}

                {data !== null && data.rows.length > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {data.columns.map((column) => (
                                    <TableHead key={column}>
                                        {drillColumnLabel(column)}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.rows.map((row, index) => (
                                // eslint-disable-next-line react/no-array-index-key
                                <TableRow key={index}>
                                    {data.columns.map((column) => (
                                        <TableCell key={column} dir="ltr">
                                            {cell(column, row[column])}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <a
                    href={query(widget, params, true)}
                    className="text-sm underline-offset-4 hover:underline"
                >
                    دانلود CSV
                </a>
            </DialogContent>
        </Dialog>
    );
}
