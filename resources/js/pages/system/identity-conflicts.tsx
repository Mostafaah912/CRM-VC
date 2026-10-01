import { Head, Link } from '@inertiajs/react';
import { PhoneRevealButton } from '@/components/customers/PhoneRevealButton';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TruncatedText } from '@/components/truncated-text';
import { useCan } from '@/hooks/use-can';
import { conflictReasons, conflictStatuses } from '@/lib/system-status';
import { dashboard } from '@/routes';
import { show as customerShow } from '@/routes/customers';
import { identityConflicts } from '@/routes/system';
import type { IdentityConflictRow, Paginated } from '@/types/system';

type Props = {
    conflicts: Paginated<IdentityConflictRow>;
};

export default function IdentityConflicts({ conflicts }: Props) {
    const can = useCan();
    const canRevealPhone = can('customers', 'view_full_phone');

    return (
        <>
            <Head title="تعارض‌های هویت" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">تعارض‌های هویت</h1>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                    <Table className="table-fixed">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-28">زمان</TableHead>
                                <TableHead className="w-28">وضعیت</TableHead>
                                <TableHead className="w-28">
                                    سفارش ووکامرس
                                </TableHead>
                                <TableHead className="w-28">دلیل</TableHead>
                                <TableHead className="w-24">مشتری</TableHead>
                                <TableHead>نام ثبت‌شده</TableHead>
                                <TableHead>نام سفارش جدید</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {conflicts.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
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
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <span className="cursor-default">
                                                    {conflictReasons[
                                                        conflict.reason
                                                    ] ?? conflict.reason}
                                                </span>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <span dir="ltr">
                                                    {conflict.reason}
                                                </span>
                                            </TooltipContent>
                                        </Tooltip>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {conflict.customer_id === null ? (
                                            '—'
                                        ) : (
                                            <span className="flex flex-col gap-0.5">
                                                <Link
                                                    href={customerShow(
                                                        conflict.customer_id,
                                                    )}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    <bdi
                                                        dir="ltr"
                                                        className="text-xs"
                                                    >
                                                        #{conflict.customer_id}
                                                    </bdi>
                                                </Link>
                                                <PhoneRevealButton
                                                    customerId={
                                                        conflict.customer_id
                                                    }
                                                    maskedPhone={
                                                        conflict.customer_phone
                                                    }
                                                    hasPermission={
                                                        canRevealPhone
                                                    }
                                                />
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {conflict.existing_name === null ? (
                                            '—'
                                        ) : (
                                            <TruncatedText
                                                value={conflict.existing_name}
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {conflict.incoming_name === null ? (
                                            '—'
                                        ) : (
                                            <TruncatedText
                                                value={conflict.incoming_name}
                                            />
                                        )}
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
