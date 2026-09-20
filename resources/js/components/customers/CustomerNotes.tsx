import { usePage } from '@inertiajs/react';
import { Loader2, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { isPageOf, useCursorList } from '@/hooks/use-cursor-list';
import { useCan } from '@/hooks/use-can';
import { validationMessage, writeHeaders } from '@/lib/http';
import { destroy, index, store } from '@/routes/customers/notes';
import type { CursorPage, NoteRow } from '@/types/customers';

type Props = {
    customerId: number;
};

const BODY_MAX = 2000;

const MESSAGES = {
    empty: 'هنوز یادداشتی ثبت نشده است.',
    placeholder: 'یادداشت تازه…',
    submit: 'ثبت',
    submitting: 'در حال ثبت…',
    delete: 'حذف',
    confirm: 'تأیید حذف؟',
    cancel: 'انصراف',
    loadMore: 'بارگذاری بیشتر',
    loading: 'در حال بارگذاری…',
    forbidden: 'دسترسی ندارید',
    failed: 'خطا در ثبت یادداشت',
    failedDelete: 'خطا در حذف یادداشت',
    unknownAuthor: 'کاربر',
};

function isNote(row: unknown): row is NoteRow {
    return (
        typeof row === 'object' &&
        row !== null &&
        'id' in row &&
        typeof row.id === 'number' &&
        'author_id' in row &&
        typeof row.author_id === 'number' &&
        'author_name' in row &&
        (row.author_name === null || typeof row.author_name === 'string') &&
        'body' in row &&
        typeof row.body === 'string' &&
        'created_at_jalali' in row &&
        typeof row.created_at_jalali === 'string' &&
        'created_at_iso' in row &&
        typeof row.created_at_iso === 'string'
    );
}

const isNotesPage = (body: unknown): body is CursorPage<NoteRow> =>
    isPageOf(body, isNote);
const noteKey = (note: NoteRow) => note.id;

/**
 * The notes of a customer, always visible under the tabs. The list is fetched on mount from its own endpoint (it is not part of the
 * page's queries). A note is plain text: React renders it as text, never as HTML. The form and the delete buttons are UX only — the
 * endpoints enforce customers.note (write) and author-or-customers.manage_notes (delete) for real; a delete button is shown to the
 * author only while they still hold customers.note, and to a customers.manage_notes holder for every note.
 */
export function CustomerNotes({ customerId }: Props) {
    const can = useCan();
    const { auth } = usePage().props;
    const canWrite = can('customers', 'note');
    const canManage = can('customers', 'manage_notes');

    const urlFor = useCallback(
        (cursor: string | null) =>
            index.url(customerId, cursor === null ? {} : { query: { cursor } }),
        [customerId],
    );
    const list = useCursorList(urlFor, isNotesPage, noteKey);
    const { loadFirst } = list;
    const started = useRef(false);

    useEffect(() => {
        if (!started.current) {
            started.current = true;
            void loadFirst();
        }
    }, [loadFirst]);

    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [confirming, setConfirming] = useState<number | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (body.trim() === '' || saving) {
            return;
        }

        setSaving(true);
        setProblem(null);

        try {
            const response = await fetch(store.url(customerId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: writeHeaders(),
                body: JSON.stringify({ body }),
            });

            if (response.status === 201) {
                setBody('');
                await loadFirst();
            } else if (response.status === 422) {
                setProblem(
                    validationMessage(await response.json(), 'body') ??
                        MESSAGES.failed,
                );
            } else {
                setProblem(
                    response.status === 403
                        ? MESSAGES.forbidden
                        : MESSAGES.failed,
                );
            }
        } catch {
            setProblem(MESSAGES.failed);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (note: NoteRow) => {
        setProblem(null);
        setConfirming(null);

        try {
            const response = await fetch(
                destroy.url({ customer: customerId, note: note.id }),
                {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: writeHeaders(),
                },
            );

            if (response.status === 204) {
                await loadFirst();
            } else {
                setProblem(
                    response.status === 403
                        ? MESSAGES.forbidden
                        : MESSAGES.failedDelete,
                );
            }
        } catch {
            setProblem(MESSAGES.failedDelete);
        }
    };

    const mayDelete = (note: NoteRow): boolean =>
        canManage || (canWrite && note.author_id === auth.user.id);

    return (
        <div className="flex flex-col gap-4">
            {canWrite && (
                <form onSubmit={submit} className="flex flex-col gap-2">
                    <textarea
                        aria-label={MESSAGES.placeholder}
                        placeholder={MESSAGES.placeholder}
                        maxLength={BODY_MAX}
                        rows={3}
                        value={body}
                        onChange={(event) => setBody(event.target.value)}
                        className="border-input bg-background focus-visible:ring-ring w-full rounded-md border p-2 text-sm outline-none focus-visible:ring-2"
                    />
                    <div className="flex items-center justify-between gap-3">
                        <span
                            className="text-muted-foreground text-xs"
                            dir="ltr"
                        >
                            {body.length}/{BODY_MAX}
                        </span>
                        <Button
                            type="submit"
                            disabled={saving || body.trim() === ''}
                        >
                            {saving ? MESSAGES.submitting : MESSAGES.submit}
                        </Button>
                    </div>
                </form>
            )}

            {problem !== null && (
                <p role="alert" className="text-destructive text-sm">
                    {problem}
                </p>
            )}

            {list.loaded && list.items.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    {MESSAGES.empty}
                </p>
            )}

            <ul className="flex flex-col gap-3">
                {list.items.map((note) => (
                    <li
                        key={note.id}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-1 rounded-lg border p-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-baseline gap-2 text-xs">
                                <span className="text-sm font-medium">
                                    {note.author_name ?? MESSAGES.unknownAuthor}
                                </span>
                                <time
                                    dateTime={note.created_at_iso}
                                    dir="ltr"
                                    className="text-muted-foreground"
                                >
                                    {note.created_at_jalali}
                                </time>
                            </div>
                            {mayDelete(note) &&
                                (confirming === note.id ? (
                                    <span className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            size="sm"
                                            onClick={() => void remove(note)}
                                        >
                                            {MESSAGES.confirm}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setConfirming(null)}
                                        >
                                            {MESSAGES.cancel}
                                        </Button>
                                    </span>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        aria-label={MESSAGES.delete}
                                        onClick={() => setConfirming(note.id)}
                                    >
                                        <Trash2
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        {MESSAGES.delete}
                                    </Button>
                                ))}
                        </div>
                        <p className="text-sm whitespace-pre-wrap">
                            {note.body}
                        </p>
                    </li>
                ))}
            </ul>

            {list.status.kind === 'error' && (
                <p role="alert" className="text-destructive text-sm">
                    {list.status.message}
                </p>
            )}

            {list.nextCursor !== null && (
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    disabled={list.status.kind === 'loading'}
                    onClick={() => void list.loadMore()}
                >
                    {list.status.kind === 'loading' ? (
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
