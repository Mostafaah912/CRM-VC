import { useCallback, useRef, useState } from 'react';
import { READ_HEADERS } from '@/lib/http';
import type { CursorPage } from '@/types/customers';

type Status =
    | { kind: 'idle' }
    | { kind: 'loading' }
    | { kind: 'error'; message: string };

const MESSAGES = {
    forbidden: 'دسترسی ندارید',
    failed: 'خطا در بارگذاری',
};

/** A page of anything: the envelope is checked here, each row by the caller's own guard. */
export function isPageOf<T>(
    body: unknown,
    isRow: (row: unknown) => row is T,
): body is CursorPage<T> {
    return (
        typeof body === 'object' &&
        body !== null &&
        'data' in body &&
        Array.isArray(body.data) &&
        body.data.every(isRow) &&
        'next_cursor' in body &&
        (body.next_cursor === null || typeof body.next_cursor === 'string') &&
        'has_more' in body &&
        typeof body.has_more === 'boolean'
    );
}

/**
 * A cursor-paged list that is fetched ON DEMAND: nothing is requested until the caller calls loadFirst() (a tab's first click, or a
 * section that is always visible on mount). loadMore() sends the cursor exactly as the server gave it, and appends. The address of
 * each page comes from the caller (a Wayfinder route); the cursor is opaque here — never built, decoded or altered.
 */
export function useCursorList<T>(
    urlFor: (cursor: string | null) => string,
    isPage: (body: unknown) => body is CursorPage<T>,
    keyOf: (row: T) => string | number,
) {
    const [items, setItems] = useState<T[]>([]);
    const [nextCursor, setNextCursor] = useState<string | null>(null);
    const [total, setTotal] = useState<number | null>(null);
    const [status, setStatus] = useState<Status>({ kind: 'idle' });
    const [loaded, setLoaded] = useState(false);
    const busy = useRef(false);

    const fetchPage = useCallback(
        async (cursor: string | null) => {
            if (busy.current) {
                return;
            }

            busy.current = true;
            setStatus({ kind: 'loading' });

            try {
                const response = await fetch(urlFor(cursor), {
                    credentials: 'same-origin',
                    headers: READ_HEADERS,
                });

                if (!response.ok) {
                    setStatus({
                        kind: 'error',
                        message:
                            response.status === 403
                                ? MESSAGES.forbidden
                                : MESSAGES.failed,
                    });

                    return;
                }

                const body: unknown = await response.json();

                if (!isPage(body)) {
                    setStatus({ kind: 'error', message: MESSAGES.failed });

                    return;
                }

                setItems((current) => {
                    if (cursor === null) {
                        return body.data;
                    }

                    const known = new Set(current.map(keyOf));

                    return [
                        ...current,
                        ...body.data.filter((row) => !known.has(keyOf(row))),
                    ];
                });
                setNextCursor(body.next_cursor);
                setTotal(body.total_count ?? null);
                setLoaded(true);
                setStatus({ kind: 'idle' });
            } catch {
                setStatus({ kind: 'error', message: MESSAGES.failed });
            } finally {
                busy.current = false;
            }
        },
        [urlFor, isPage, keyOf],
    );

    return {
        items,
        nextCursor,
        total,
        status,
        loaded,
        /** Fetches (or re-fetches) the first page, replacing what is shown. */
        loadFirst: () => fetchPage(null),
        /** Fetches the page after the last one shown, if there is one. */
        loadMore: () =>
            nextCursor === null ? undefined : fetchPage(nextCursor),
    };
}
