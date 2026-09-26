import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import {
    create as segmentsCreate,
    index as segmentsIndex,
    show as segmentsShow,
} from '@/routes/segments';
import type { SegmentListRow } from '@/types/segments';
import type { Paginated } from '@/types/system';

type Props = {
    segments: Paginated<SegmentListRow>;
};

const TYPE_LABELS: Record<string, string> = {
    dynamic: 'پویا',
    static: 'ایستا',
    manual: 'دستی',
};

const ACTIVE_LABELS = {
    active: { label: 'فعال', tone: 'success' as const },
    inactive: { label: 'غیرفعال', tone: 'neutral' as const },
};

export default function SegmentsIndex({ segments }: Props) {
    const can = useCan();

    return (
        <>
            <Head title="سگمنت‌ها" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <h1 className="text-xl font-medium">سگمنت‌ها</h1>
                    <div className="flex items-center gap-4">
                        <span className="text-muted-foreground text-sm">
                            {segments.total} سگمنت
                        </span>
                        {can('segments', 'create') && (
                            <Button asChild size="sm">
                                <Link href={segmentsCreate()}>
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    سگمنت جدید
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>نام</TableHead>
                                <TableHead>نوع</TableHead>
                                <TableHead>تعداد اعضا</TableHead>
                                <TableHead>آخرین ارزیابی</TableHead>
                                <TableHead>وضعیت</TableHead>
                                <TableHead>سیستمی</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {segments.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground text-center"
                                    >
                                        سگمنتی پیدا نشد.
                                    </TableCell>
                                </TableRow>
                            )}
                            {segments.data.map((segment) => (
                                <TableRow key={segment.id}>
                                    <TableCell className="text-sm font-medium">
                                        <Link
                                            href={segmentsShow(segment.id)}
                                            className="hover:underline"
                                        >
                                            {segment.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {TYPE_LABELS[segment.type] ??
                                            segment.type}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {segment.last_evaluated_at === null ? (
                                            <span className="text-muted-foreground">
                                                ارزیابی نشده
                                            </span>
                                        ) : (
                                            segment.member_count.toLocaleString(
                                                'fa-IR',
                                            )
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {segment.last_evaluated_at === null ? (
                                            '—'
                                        ) : (
                                            <span dir="ltr">
                                                {segment.last_evaluated_at}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={
                                                segment.is_active
                                                    ? 'active'
                                                    : 'inactive'
                                            }
                                            labels={ACTIVE_LABELS}
                                        />
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {segment.is_system ? 'بله' : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    currentPage={segments.current_page}
                    lastPage={segments.last_page}
                    prevUrl={segments.prev_page_url}
                    nextUrl={segments.next_page_url}
                />
            </div>
        </>
    );
}

SegmentsIndex.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'سگمنت‌ها', href: segmentsIndex() },
    ],
};
