import { Head } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { conflictReasons, conflictStatuses } from '@/lib/system-status';
import { dashboard } from '@/routes';
import { identityConflicts } from '@/routes/system';
import type { IdentityConflictRow, Paginated } from '@/types/system';

type Props = {
    conflicts: Paginated<IdentityConflictRow>;
};

export default function IdentityConflicts({ conflicts }: Props) {
    return (
        <>
            <Head title="تعارض‌های هویت" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">تعارض‌های هویت</h1>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>زمان</TableHead>
                                <TableHead>وضعیت</TableHead>
                                <TableHead>سفارش ووکامرس</TableHead>
                                <TableHead>دلیل</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {conflicts.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="text-muted-foreground text-center"
                                    >
                                        هیچ تعارض هویتی ثبت نشده است.
                                    </TableCell>
                                </TableRow>
                            )}
                            {conflicts.data.map((conflict, index) => (
                                <TableRow
                                    key={`${conflict.created_at}-${index}`}
                                >
                                    <TableCell className="text-sm">
                                        <span dir="ltr">
                                            {conflict.created_at}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={conflict.status}
                                            labels={conflictStatuses}
                                        />
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {conflict.woo_order_id === null ? (
                                            '—'
                                        ) : (
                                            <span dir="ltr">
                                                {conflict.woo_order_id}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {conflictReasons[conflict.reason] ??
                                            conflict.reason}
                                        <span
                                            dir="ltr"
                                            className="text-muted-foreground ms-2 font-mono text-xs"
                                        >
                                            {conflict.reason}
                                        </span>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    currentPage={conflicts.current_page}
                    lastPage={conflicts.last_page}
                    prevUrl={conflicts.prev_page_url}
                    nextUrl={conflicts.next_page_url}
                />
            </div>
        </>
    );
}

IdentityConflicts.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'تعارض‌های هویت', href: identityConflicts() },
    ],
};
