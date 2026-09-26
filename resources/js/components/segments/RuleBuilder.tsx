import { Loader2, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { RuleNodeEditor } from '@/components/segments/RuleNodeEditor';
import { validateRuleTree } from '@/lib/segment-rule-validation';
import {
    addChild,
    emptyCondition,
    emptyGroup,
    removeNode,
    toWireRule,
    updateNode,
} from '@/lib/segment-rule-tree';
import { preview as previewRoute } from '@/routes/segments';
import type { RuleTreeNode, RuleWhitelist } from '@/types/segments';

type Props = {
    whitelist: RuleWhitelist;
    /** Uncontrolled by default (builds its own draft tree); pass both to control it from a parent (P5-06's create/edit page). */
    value?: RuleTreeNode;
    onChange?: (tree: RuleTreeNode) => void;
};

type PreviewState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; count: number }
    | { status: 'error'; message: string };

const MESSAGES = {
    forbidden: 'دسترسی به پیش‌نمایش ندارید',
    serverError: 'خطای سرور، لطفاً بعداً تلاش کنید',
    failed: 'خطا در محاسبه‌ی پیش‌نمایش',
    fixErrors: 'قبل از پیش‌نمایش، خطاهای بالا را برطرف کنید',
};

/** Laravel puts the CSRF token for scripts in this cookie; a POST without it is refused. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function messageFrom(status: number, body: unknown): string {
    if (status === 403) {
        return MESSAGES.forbidden;
    }

    if (status >= 500) {
        return MESSAGES.serverError;
    }

    if (
        typeof body === 'object' &&
        body !== null &&
        'message' in body &&
        typeof body.message === 'string'
    ) {
        return body.message;
    }

    return MESSAGES.failed;
}

/**
 * PRD §17/§18: a nested AND/OR rule tree, built entirely from the P5-01 whitelist served by the
 * backend (no field/operator is ever hand-written here) — with a live preview count. Client-side
 * validation (validateRuleTree) is UX only; RuleValidator on the server is the only authority, and
 * Preview calls it for real on every click.
 */
export function RuleBuilder({ whitelist, value, onChange }: Props) {
    const [uncontrolled, setUncontrolled] = useState<RuleTreeNode>(() =>
        emptyGroup('AND'),
    );
    const tree = value ?? uncontrolled;
    const setTree = onChange ?? setUncontrolled;

    const [preview, setPreview] = useState<PreviewState>({ status: 'idle' });

    const errors = useMemo(
        () => validateRuleTree(tree, whitelist),
        [tree, whitelist],
    );
    const hasErrors = errors.size > 0;

    const handleChange = (
        id: string,
        updater: (node: RuleTreeNode) => RuleTreeNode,
    ) => {
        setTree(updateNode(tree, id, updater));
        setPreview({ status: 'idle' });
    };

    const handleRemove = (id: string) => {
        setTree(removeNode(tree, id));
        setPreview({ status: 'idle' });
    };

    const handleAddCondition = (groupId: string) => {
        setTree(addChild(tree, groupId, emptyCondition()));
        setPreview({ status: 'idle' });
    };

    const handleAddGroup = (groupId: string) => {
        setTree(addChild(tree, groupId, emptyGroup('AND')));
        setPreview({ status: 'idle' });
    };

    const runPreview = async () => {
        setPreview({ status: 'loading' });

        try {
            const response = await fetch(previewRoute.url(), {
                method: 'post',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ rule: toWireRule(tree) }),
            });

            const body: unknown = await response.json().catch(() => null);

            if (!response.ok) {
                setPreview({
                    status: 'error',
                    message: messageFrom(response.status, body),
                });

                return;
            }

            const count =
                typeof body === 'object' &&
                body !== null &&
                'count' in body &&
                typeof body.count === 'number'
                    ? body.count
                    : null;

            setPreview(
                count === null
                    ? { status: 'error', message: MESSAGES.failed }
                    : { status: 'success', count },
            );
        } catch {
            setPreview({ status: 'error', message: MESSAGES.failed });
        }
    };

    return (
        <div className="space-y-4" dir="rtl">
            <RuleNodeEditor
                node={tree}
                whitelist={whitelist}
                errors={errors}
                depth={0}
                onChange={handleChange}
                onRemove={handleRemove}
                onAddCondition={handleAddCondition}
                onAddGroup={handleAddGroup}
            />

            <div className="flex items-center gap-3">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={hasErrors || preview.status === 'loading'}
                    aria-busy={preview.status === 'loading'}
                    onClick={runPreview}
                >
                    {preview.status === 'loading' ? (
                        <Loader2
                            className="size-4 animate-spin"
                            aria-hidden="true"
                        />
                    ) : (
                        <Users className="size-4" aria-hidden="true" />
                    )}
                    پیش‌نمایش تعداد مشتری
                </Button>

                {hasErrors && preview.status === 'idle' && (
                    <span className="text-muted-foreground text-sm">
                        {MESSAGES.fixErrors}
                    </span>
                )}

                {preview.status === 'success' && (
                    <span className="text-sm font-medium">
                        {preview.count.toLocaleString('fa-IR')} مشتری
                    </span>
                )}

                {preview.status === 'error' && (
                    <span role="alert" className="text-destructive text-sm">
                        {preview.message}
                    </span>
                )}
            </div>
        </div>
    );
}
