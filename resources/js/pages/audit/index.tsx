import { Head, Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';

type AuditActorType = 'user' | 'system' | 'ai';

type AuditLogRow = {
    id: number;
    actor_type: AuditActorType;
    action: string;
    auditable_type: string;
    auditable_id: number;
    ip: string | null;
    created_at: string;
    user: { id: number; name: string; email: string } | null;
};

type PaginatedLogs = {
    data: AuditLogRow[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    logs: PaginatedLogs;
};

const actorLabels: Record<AuditActorType, string> = {
    user: 'کاربر',
    system: 'سیستم',
    ai: 'هوش مصنوعی',
};

export default function AuditIndex({ logs }: Props) {
    return (
        <>
            <Head title="گزارش رخدادها" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">گزارش رخدادها</h1>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>عامل</TableHead>
                                <TableHead>رخداد</TableHead>
                                <TableHead>هدف</TableHead>
                                <TableHead>آی‌پی</TableHead>
                                <TableHead>زمان</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground text-center"
                                    >
                                        هیچ رخدادی ثبت نشده است.
                                    </TableCell>
                                </TableRow>
                            )}
                            {logs.data.map((log) => (
                                <TableRow key={log.id}>
                                    <TableCell>
                                        <Badge variant="secondary">
                                            {actorLabels[log.actor_type]}
                                        </Badge>
                                        {log.user && (
                                            <span className="text-muted-foreground ms-2 text-sm">
                                                {log.user.name}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-sm">
                                        {log.action}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-sm">
                                        {log.auditable_type}#{log.auditable_id}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-sm">
                                        {log.ip ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-sm">
                                        {log.created_at}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {logs.last_page > 1 && (
                    <div className="flex items-center justify-center gap-4">
                        {logs.prev_page_url ? (
                            <Link
                                href={logs.prev_page_url}
                                preserveScroll
                                className="text-sm hover:underline"
                            >
                                قبلی
                            </Link>
                        ) : (
                            <span className="text-muted-foreground text-sm opacity-50">
                                قبلی
                            </span>
                        )}

                        <span className="text-muted-foreground text-sm">
                            صفحه {logs.current_page} از {logs.last_page}
                        </span>

                        {logs.next_page_url ? (
                            <Link
                                href={logs.next_page_url}
                                preserveScroll
                                className="text-sm hover:underline"
                            >
                                بعدی
                            </Link>
                        ) : (
                            <span className="text-muted-foreground text-sm opacity-50">
                                بعدی
                            </span>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

AuditIndex.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'گزارش رخدادها', href: '/audit' },
    ],
};
