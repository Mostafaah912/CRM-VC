import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { customerStatuses, lifecycleStages } from '@/lib/customer-labels';
import { statusInfo } from '@/lib/system-status';
import { dashboard } from '@/routes';
import { index as customersIndex } from '@/routes/customers';
import type {
    CustomerFilters,
    CustomerOptions,
    CustomerRow,
} from '@/types/customers';
import type { Paginated } from '@/types/system';

type Props = {
    customers: Paginated<CustomerRow>;
    filters: CustomerFilters;
    options: CustomerOptions;
};

const ALL = 'all';

type FormState = {
    search: string;
    status: string;
    lifecycle_stage: string;
    province: string;
    city: string;
    needs_review: boolean;
    first_seen_from: string;
    first_seen_to: string;
};

function initialState(filters: CustomerFilters): FormState {
    return {
        search: filters.search ?? '',
        status: filters.status ?? ALL,
        lifecycle_stage: filters.lifecycle_stage ?? ALL,
        province: filters.province ?? ALL,
        city: filters.city ?? ALL,
        needs_review: filters.needs_review === true,
        first_seen_from: filters.first_seen_from ?? '',
        first_seen_to: filters.first_seen_to ?? '',
    };
}

/** Only what was actually chosen goes into the address, so a clean page has a clean URL. */
function toQuery(state: FormState): Record<string, string> {
    const query: Record<string, string> = {};
    const text: [string, string][] = [
        ['search', state.search.trim()],
        ['first_seen_from', state.first_seen_from.trim()],
        ['first_seen_to', state.first_seen_to.trim()],
    ];
    const choices: [string, string][] = [
        ['status', state.status],
        ['lifecycle_stage', state.lifecycle_stage],
        ['province', state.province],
        ['city', state.city],
    ];

    for (const [key, value] of text) {
        if (value !== '') {
            query[key] = value;
        }
    }

    for (const [key, value] of choices) {
        if (value !== ALL) {
            query[key] = value;
        }
    }

    if (state.needs_review) {
        query.needs_review = '1';
    }

    return query;
}

function ChoiceSelect({
    label,
    value,
    choices,
    onChange,
}: {
    label: string;
    value: string;
    choices: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="flex min-w-40 flex-col gap-1.5">
            <Label>{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger aria-label={label}>
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
        </div>
    );
}

export default function CustomersIndex({ customers, filters, options }: Props) {
    const [state, setState] = useState<FormState>(initialState(filters));
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const messages = Object.values(errors ?? {});

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setState((current) => ({ ...current, [key]: value }));

    const go = (next: FormState) =>
        router.get(
            customersIndex.url({ query: toQuery(next) }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        go(state);
    };

    const reset = () => {
        const cleared = initialState({
            search: null,
            status: null,
            lifecycle_stage: null,
            province: null,
            city: null,
            needs_review: null,
            first_seen_from: null,
            first_seen_to: null,
        });

        setState(cleared);
        go(cleared);
    };

    return (
        <>
            <Head title="مشتریان" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <h1 className="text-xl font-medium">مشتریان</h1>
                    <span className="text-muted-foreground text-sm">
                        {customers.total} مشتری
                    </span>
                </div>

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="customer-search">
                            جستجو (نام یا شماره موبایل)
                        </Label>
                        <Input
                            id="customer-search"
                            type="search"
                            maxLength={100}
                            value={state.search}
                            onChange={(event) =>
                                set('search', event.target.value)
                            }
                            placeholder="مثلاً مریم رضایی یا ۰۹۱۲۳۴۵۶۷۸۹"
                        />
                    </div>

                    <div className="flex flex-wrap items-end gap-4">
                        <ChoiceSelect
                            label="وضعیت"
                            value={state.status}
                            choices={options.statuses.map((status) => ({
                                value: status,
                                label: statusInfo(customerStatuses, status)
                                    .label,
                            }))}
                            onChange={(value) => set('status', value)}
                        />
                        <ChoiceSelect
                            label="مرحله‌ی چرخه‌ی عمر"
                            value={state.lifecycle_stage}
                            choices={options.lifecycle_stages.map((stage) => ({
                                value: stage,
                                label: statusInfo(lifecycleStages, stage).label,
                            }))}
                            onChange={(value) => set('lifecycle_stage', value)}
                        />
                        <ChoiceSelect
                            label="استان"
                            value={state.province}
                            choices={options.provinces.map((province) => ({
                                value: province,
                                label: province,
                            }))}
                            onChange={(value) => set('province', value)}
                        />
                        <ChoiceSelect
                            label="شهر"
                            value={state.city}
                            choices={options.cities.map((city) => ({
                                value: city,
                                label: city,
                            }))}
                            onChange={(value) => set('city', value)}
                        />
                    </div>

                    <div className="flex flex-wrap items-end gap-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="first-seen-from">
                                اولین مشاهده از (شمسی)
                            </Label>
                            <Input
                                id="first-seen-from"
                                dir="ltr"
                                className="w-40"
                                placeholder="1405/01/01"
                                value={state.first_seen_from}
                                onChange={(event) =>
                                    set('first_seen_from', event.target.value)
                                }
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="first-seen-to">
                                اولین مشاهده تا (شمسی)
                            </Label>
                            <Input
                                id="first-seen-to"
                                dir="ltr"
                                className="w-40"
                                placeholder="1405/06/29"
                                value={state.first_seen_to}
                                onChange={(event) =>
                                    set('first_seen_to', event.target.value)
                                }
                            />
                        </div>
                        <div className="flex items-center gap-2 pb-2">
                            <Checkbox
                                id="needs-review"
                                checked={state.needs_review}
                                onCheckedChange={(checked) =>
                                    set('needs_review', checked === true)
                                }
                            />
                            <Label htmlFor="needs-review">
                                فقط نیازمند بازبینی
                            </Label>
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
                                <TableHead>نام</TableHead>
                                <TableHead>موبایل</TableHead>
                                <TableHead>وضعیت</TableHead>
                                <TableHead>مرحله</TableHead>
                                <TableHead>استان</TableHead>
                                <TableHead>شهر</TableHead>
                                <TableHead>اولین مشاهده</TableHead>
                                <TableHead>بازبینی</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="text-muted-foreground text-center"
                                    >
                                        مشتری‌ای با این شرایط پیدا نشد.
                                    </TableCell>
                                </TableRow>
                            )}
                            {customers.data.map((customer) => (
                                <TableRow key={customer.id}>
                                    <TableCell className="text-sm font-medium">
                                        {customer.display_name ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        <span dir="ltr" className="font-mono">
                                            {customer.phone}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={customer.status}
                                            labels={customerStatuses}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={customer.lifecycle_stage}
                                            labels={lifecycleStages}
                                        />
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {customer.province ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {customer.city ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {customer.first_seen_at === null ? (
                                            '—'
                                        ) : (
                                            <span dir="ltr">
                                                {customer.first_seen_at}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {customer.needs_review && (
                                            <StatusBadge
                                                status="needs_review"
                                                labels={{
                                                    needs_review: {
                                                        label: 'نیازمند بازبینی',
                                                        tone: 'warning',
                                                    },
                                                }}
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    currentPage={customers.current_page}
                    lastPage={customers.last_page}
                    prevUrl={customers.prev_page_url}
                    nextUrl={customers.next_page_url}
                />
            </div>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'مشتریان', href: customersIndex() },
    ],
};
