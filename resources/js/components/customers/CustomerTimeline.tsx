import {
    Circle,
    Loader2,
    RefreshCw,
    ShoppingBag,
    StickyNote,
    Undo2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { customerStatuses, orderStatuses } from '@/lib/customer-labels';
import { timeline } from '@/routes/customers';
import type { TimelineEvent, TimelineResponse } from '@/types/customers';

type Props = {
    customerId: number;
    /** The first page, from the page's own props. */
    initialData: TimelineResponse;
};

type Load =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string };

const MESSAGES = {
    loadMore: 'بارگذاری بیشتر',
    loading: 'در حال بارگذاری…',
    forbidden: 'دسترسی ندارید',
    failed: 'خطا در بارگذاری رویدادها',
};

const KINDS: Record<string, { icon: LucideIcon; label: string }> = {
    order_placed: { icon: ShoppingBag, label: 'ثبت سفارش' },
    order_refunded: { icon: Undo2, label: 'عودت سفارش' },
    note_added: { icon: StickyNote, label: 'یادداشت' },
    status_changed: { icon: RefreshCw, label: 'تغییر وضعیت' },
};

const FALLBACK_KIND = { icon: Circle, label: 'رویداد' };

/** A status code in the words the app uses for it; any code this build does not know is shown as stored. */
function statusLabel(code: unknown): string {
    if (typeof code !== 'string') {
        return '—';
    }

    return customerStatuses[code]?.label ?? orderStatuses[code]?.label ?? code;
}

/** One line about an event, from the few payload keys the server lets through. Plain text only. */
function describe(event: TimelineEvent): string {
    const payload = event.payload;
    const order = payload?.woo_order_id ?? payload?.order_id ?? null;
    const orderText = order === null ? '' : ` شماره ${String(order)}`;

    switch (event.event_type) {
        case 'order_placed':
            return `سفارش${orderText} ثبت شد`;
        case 'order_refunded':
            return `عودت سفارش${orderText}`;
        case 'note_added':
            return typeof payload?.note === 'string' && payload.note !== ''
                ? payload.note
                : 'یادداشت افزوده شد';
        case 'status_changed':
            return `وضعیت از «${statusLabel(payload?.old_status)}» به «${statusLabel(payload?.new_status)}» تغییر کرد`;
        default:
            return event.event_type;
    }
}

function isEvent(value: unknown): value is TimelineEvent {
    return (
        typeof value === 'object' &&
        value !== null &&
        'id' in value &&
        typeof value.id === 'number' &&
        'event_type' in value &&
        typeof value.event_type === 'string' &&
        'happened_at_jalali' in value &&
        typeof value.happened_at_jalali === 'string' &&
        'happened_at_iso' in value &&
        typeof value.happened_at_iso === 'string'
    );
}

function isTimelineResponse(body: unknown): body is TimelineResponse {
    return (
        typeof body === 'object' &&
        body !== null &&
        'data' in body &&
        Array.isArray(body.data) &&
        body.data.every(isEvent) &&
        'next_cursor' in body &&
        (body.next_cursor === null || typeof body.next_cursor === 'string') &&
        'has_more' in body &&
        typeof body.has_more === 'boolean'
    );
}

/**
 * A customer's timeline: newest first down the page, a line on the inline-start edge (the right, in RTL), one icon and one line
 * of text per event. The first page arrives with the page; "بارگذاری بیشتر" asks the endpoint for the next one with the cursor the
 * server gave, and appends it. The cursor is opaque here — passed back untouched, never built or read.
 */
export function CustomerTimeline({ customerId, initialData }: Props) {
    const [events, setEvents] = useState<TimelineEvent[]>(initialData.data);
    const [nextCursor, setNextCursor] = useState<string | null>(
        initialData.next_cursor,
    );
    const [load, setLoad] = useState<Load>({ status: 'idle' });

    const loadMore = async () => {
        if (nextCursor === null) {
            return;
        }

        setLoad({ status: 'loading' });

        try {
            const response = await fetch(
                timeline.url(customerId, { query: { cursor: nextCursor } }),
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (!response.ok) {
                setLoad({
                    status: 'error',
                    message:
                        response.status === 403
                            ? MESSAGES.forbidden
                            : MESSAGES.failed,
                });

                return;
            }

            const body: unknown = await response.json();

            if (!isTimelineResponse(body)) {
                setLoad({ status: 'error', message: MESSAGES.failed });

                return;
            }

            setEvents((current) => {
                const known = new Set(current.map((event) => event.id));

                return [
                    ...current,
                    ...body.data.filter((event) => !known.has(event.id)),
                ];
            });
            setNextCursor(body.next_cursor);
            setLoad({ status: 'idle' });
        } catch {
            setLoad({ status: 'error', message: MESSAGES.failed });
        }
    };

    return (
        <div className="flex flex-col gap-4">
            <ol className="border-sidebar-border relative border-s">
                {events.map((event) => {
                    const kind = KINDS[event.event_type] ?? FALLBACK_KIND;
                    const Icon = kind.icon;

                    return (
                        <li key={event.id} className="ms-6 mb-6 last:mb-0">
                            <span className="bg-secondary text-secondary-foreground absolute -start-3 flex size-6 items-center justify-center rounded-full">
                                <Icon aria-hidden="true" className="size-3.5" />
                            </span>
                            <div className="flex flex-col gap-0.5">
                                <div className="flex flex-wrap items-baseline gap-2">
                                    <span className="text-sm font-medium">
                                        {kind.label}
                                    </span>
                                    <time
                                        dateTime={event.happened_at_iso}
                                        dir="ltr"
                                        className="text-muted-foreground text-xs"
                                    >
                                        {event.happened_at_jalali}
                                    </time>
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {describe(event)}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>

            {load.status === 'error' && (
                <p role="alert" className="text-destructive text-sm">
                    {load.message}
                </p>
            )}

            {nextCursor !== null && (
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    disabled={load.status === 'loading'}
                    onClick={loadMore}
                >
                    {load.status === 'loading' ? (
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
