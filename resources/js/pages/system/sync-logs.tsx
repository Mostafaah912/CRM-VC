import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
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
import { syncEntities, syncStatuses, statusInfo } from '@/lib/system-status';
import { dashboard } from '@/routes';
import { syncLogs } from '@/routes/system';
import type {
    Paginated,
    SyncLogFilters,
    SyncLogOptions,
    SyncRun,
} from '@/types/system';

type Props = {
    runs: Paginated<SyncRun>;
    filters: SyncLogFilters;
    options: SyncLogOptions;
};

const ALL = 'all';

function entityLabel(entity: string): string {
    return syncEntities[entity] ?? entity;
}

function Ltr({ children }: { children: React.ReactNode }) {
    return <span dir="ltr">{children}</span>;
}

function FilterSelect({
    label,
    value,
    choices,
    onChange,
}: {
    label: string;
    value: string | null;
    choices: { value: string; label: string }[];
    onChange: (value: string | null) => void;
}) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <Select
                value={value ?? ALL}
                onValueChange={(next) => onChange(next === ALL ? null : next)}
            >
                <SelectTrigger className="w-40" aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>همه</SelectItem>
                    {choices.map((choice) => (
                        <SelectItem key={choice.value} value={choice.value}>
                            {choice.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </label>
    );
}

export default function SyncLogs({ runs, filters, options }: Props) {
    const [expanded, setExpanded] = useState<ReadonlySet<number>>(new Set());

    const applyFilters = (next: SyncLogFilters) => {
        const query: Record<string, string> = {};

        if (next.status !== null) {
            query.status = next.status;
        }

        if (next.entity !== null) {
            query.entity = next.entity;
        }

        router.get(
            syncLogs.url({ query }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    const toggle = (index: number) => {
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }

            return next;
        });
    };

    return (
        <>
            <Head title="گزارش همگام‌سازی" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">گزارش همگام‌سازی</h1>

                <div className="flex flex-wrap items-center gap-4">
                    <FilterSelect
                        label="وضعیت"
                        value={filters.status}
                        choices={options.statuses.map((status) => ({
                            value: status,
                            label: statusInfo(syncStatuses, status).label,
                        }))}
                        onChange={(status) =>
                            applyFilters({ ...filters, status })
                        }
                    />
                    <FilterSelect
                        label="نوع"
                        value={filters.entity}
                        choices={options.entities.map((entity) => ({
                            value: entity,
                            label: entityLabel(entity),
                        }))}
                        onChange={(entity) =>
                            applyFilters({ ...filters, entity })
                        }
                    />
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>شروع</TableHead>
                                <TableHead>نوع</TableHead>
                                <TableHead>وضعیت</TableHead>
                                <TableHead>صفحه‌ها</TableHead>
                                <TableHead>سفارش‌ها</TableHead>
                                <TableHead>پایان</TableHead>
                                <TableHead>مدت (ثانیه)</TableHead>
                                <TableHead>
                                    <span className="sr-only">جزئیات</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="text-muted-foreground text-center"
                                    >
                                        هیچ همگام‌سازی‌ای ثبت نشده است.
                                    </TableCell>
                                </TableRow>
                            )}
                            {runs.data.map((run, index) => {
                                const open = expanded.has(index);

                                return (
                                    <Fragment
                                        key={`${run.started_at}-${index}`}
                                    >
                                        <TableRow>
                                            <TableCell className="text-sm">
                                                <Ltr>{run.started_at}</Ltr>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {entityLabel(run.entity)}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={run.status}
                                                    labels={syncStatuses}
                                                />
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {run.pages_processed}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {run.records_processed}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {run.finished_at === null ? (
                                                    '—'
                                                ) : (
                                                    <Ltr>{run.finished_at}</Ltr>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {run.duration_seconds ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {run.error !== null && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-expanded={open}
                                                        onClick={() =>
                                                            toggle(index)
                                                        }
                                                    >
                                                        خطا
                                                        {open ? (
                                                            <ChevronUp />
                                                        ) : (
                                                            <ChevronDown />
                                                        )}
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                        {open && run.error !== null && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={8}
                                                    className="bg-muted/40 text-sm break-words whitespace-pre-wrap"
                                                >
                                                    {run.error}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    currentPage={runs.current_page}
                    lastPage={runs.last_page}
                    prevUrl={runs.prev_page_url}
                    nextUrl={runs.next_page_url}
                />
            </div>
        </>
    );
}

SyncLogs.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'گزارش همگام‌سازی', href: syncLogs() },
    ],
};
