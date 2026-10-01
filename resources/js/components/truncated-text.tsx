import { cn } from '@/lib/utils';

/**
 * P6-13: a list-table cell whose text could occasionally run long (a customer's full display name) —
 * single-line ellipsis instead of forcing the column, and the table, wider than its card; the full text
 * is always available in a native `title` tooltip on hover.
 */
export function TruncatedText({
    value,
    className,
}: {
    value: string;
    className?: string;
}) {
    return (
        <span
            className={cn('block max-w-[12rem] truncate', className)}
            title={value}
        >
            {value}
        </span>
    );
}
