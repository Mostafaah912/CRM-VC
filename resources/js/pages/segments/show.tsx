import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { PhoneRevealButton } from '@/components/customers/PhoneRevealButton';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { useCan } from '@/hooks/use-can';
import { customerStatuses, lifecycleStages } from '@/lib/customer-labels';
import { dashboard } from '@/routes';
import {
    destroy as segmentsDestroy,
    edit as segmentsEdit,
    evaluate as segmentsEvaluate,
    exportMethod as segmentsExport,
    index as segmentsIndex,
} from '@/routes/segments';
import type { SegmentDetail, SegmentMemberRow } from '@/types/segments';
import type { Paginated } from '@/types/system';

type Props = {
    segment: SegmentDetail;
    members: Paginated<SegmentMemberRow>;
};

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-lg">{value}</CardTitle>
            </CardHeader>
        </Card>
    );
}

export default function SegmentShow({ segment, members }: Props) {
    const can = useCan();
    const canRevealPhone = can('customers', 'view_full_phone');
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const messages = Object.values(errors ?? {});

    const evaluate = () => {
        router.post(
            segmentsEvaluate.url(segment.id),
            {},
            { preserveScroll: true },
        );
    };

    const destroy = () => {
        if (window.confirm(`سگمنت «${segment.name}» حذف شود؟`)) {
            router.delete(segmentsDestroy.url(segment.id));
        }
    };

    return (
        <>
            <Head title={segment.name} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-medium">{segment.name}</h1>
                        {segment.description && (
                            <p className="text-muted-foreground mt-1 text-sm">
                                {segment.description}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can('segments', 'edit') && !segment.is_system && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={segmentsEdit(segment.id)}>
                                    <Pencil
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    ویرایش
                                </Link>
                            </Button>
                        )}
                        {can('segments', 'edit') && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={evaluate}
                            >
                                <RefreshCw
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                ارزیابی
                            </Button>
                        )}
                        {can('customers', 'export') && (
                            <Button asChild variant="outline" size="sm">
                                <a href={segmentsExport.url(segment.id)}>
                                    <Download
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    خروجی
                                </a>
                            </Button>
                        )}
                        {can('segments', 'delete') && !segment.is_system && (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={destroy}
                            >
                                <Trash2 className="size-4" aria-hidden="true" />
                                حذف
                            </Button>
                        )}
                    </div>
                </div>

                {segment.is_system && (
                    <Alert>
                        <AlertDescription>
                            این سگمنت سیستمی است و قابل ویرایش یا حذف نیست.
                        </AlertDescription>
                    </Alert>
                )}

                {messages.length > 0 && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            <ul role="alert" className="list-inside list-disc">
                                {messages.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <Stat
                        label="تعداد اعضا"
                        value={
                            segment.last_evaluated_at === null ? (
                                <span className="text-muted-foreground text-sm">
                                    ارزیابی نشده
                                </span>
                            ) : (
                                segment.member_count.toLocaleString('fa-IR')
                            )
                        }
                    />
                    <Stat
                        label="آخرین ارزیابی"
                        value={
                            segment.last_evaluated_at === null ? (
                                '—'
                            ) : (
                                <span dir="ltr" className="text-sm">
                                    {segment.last_evaluated_at}
                                </span>
                            )
                        }
                    />
                    <Stat
                        label="مدت آخرین ارزیابی"
                        value={
                            segment.last_eval_ms === null
                                ? '—'
                                : `${segment.last_eval_ms.toLocaleString('fa-IR')} ms`
                        }
                    />
                    <Stat
                        label="وضعیت"
                        value={segment.is_active ? 'فعال' : 'غیرفعال'}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardDescription>خلاصه‌ی قانون سگمنت</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <pre
                            className="bg-muted overflow-x-auto rounded-md p-3 text-xs"
                            dir="ltr"
                        >
                            {JSON.stringify(segment.rule, null, 2)}
                        </pre>
                    </CardContent>
                </Card>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>نام</TableHead>
                                <TableHead>موبایل</TableHead>
                                <TableHead>وضعیت</TableHead>
                                <TableHead>مرحله</TableHead>
                                <TableHead>افزوده‌شده در</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground text-center"
                                    >
                                        عضوی پیدا نشد.
                                    </TableCell>
                                </TableRow>
                            )}
                            {members.data.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell className="text-sm font-medium">
                                        {member.display_name ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        <PhoneRevealButton
                                            customerId={member.id}
                                            maskedPhone={member.phone}
                                            hasPermission={canRevealPhone}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={member.status}
                                            labels={customerStatuses}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={member.lifecycle_stage}
                                            labels={lifecycleStages}
                                        />
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        <span dir="ltr">{member.added_at}</span>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    currentPage={members.current_page}
                    lastPage={members.last_page}
                    prevUrl={members.prev_page_url}
                    nextUrl={members.next_page_url}
                />
            </div>
        </>
    );
}

SegmentShow.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'سگمنت‌ها', href: segmentsIndex() },
    ],
};
