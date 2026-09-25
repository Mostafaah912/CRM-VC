import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { RuleBuilder } from '@/components/segments/RuleBuilder';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { emptyGroup, fromWireRule, toWireRule } from '@/lib/segment-rule-tree';
import type { RuleTreeNode, RuleWhitelist, WireRule } from '@/types/segments';

type Props = {
    whitelist: RuleWhitelist;
    action: string;
    submitLabel: string;
    initialName?: string;
    initialDescription?: string | null;
    initialRule?: WireRule;
    /** An is_system segment (P5-07's seeds): SegmentService::modify() refuses regardless, so the form is read-only here too. */
    locked?: boolean;
};

/**
 * PRD §25/P5-06: the create/edit segment form — name, description, the RuleBuilder (P5-05). Server
 * errors (`errors.name`/`errors.rule`/`errors.segment`, from the FormRequest or the
 * SegmentException/RuleValidationException -> back()->withErrors() mapping in bootstrap/app.php) are
 * the only validation authority; the RuleBuilder's own client-side check is UX only.
 */
export function SegmentForm({
    whitelist,
    action,
    submitLabel,
    initialName = '',
    initialDescription = null,
    initialRule,
    locked = false,
}: Props) {
    const [tree, setTree] = useState<RuleTreeNode>(() =>
        initialRule === undefined
            ? emptyGroup('AND')
            : fromWireRule(initialRule),
    );
    const form = useForm({
        name: initialName,
        description: initialDescription ?? '',
    });
    // `rule` (injected only at submit time via transform()) and `segment` (a non-field error key from
    // the SegmentException -> back()->withErrors() mapping, bootstrap/app.php) are never part of the
    // form's own data shape, so useForm's inferred FormDataErrors type does not know them.
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, rule: toWireRule(tree) }));
        form.post(action);
    };

    if (locked) {
        return (
            <Alert>
                <AlertDescription>
                    این سگمنت سیستمی است و قابل ویرایش نیست.
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6" dir="rtl">
            {errors.segment && (
                <Alert variant="destructive">
                    <AlertDescription>{errors.segment}</AlertDescription>
                </Alert>
            )}

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="segment-name">نام</Label>
                <Input
                    id="segment-name"
                    value={form.data.name}
                    maxLength={120}
                    aria-invalid={!!errors.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                {errors.name && (
                    <p role="alert" className="text-destructive text-sm">
                        {errors.name}
                    </p>
                )}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="segment-description">توضیح (اختیاری)</Label>
                <Input
                    id="segment-description"
                    value={form.data.description}
                    maxLength={1000}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label>قانون سگمنت</Label>
                <RuleBuilder
                    whitelist={whitelist}
                    value={tree}
                    onChange={setTree}
                />
                {errors.rule && (
                    <p role="alert" className="text-destructive text-sm">
                        {errors.rule}
                    </p>
                )}
            </div>

            <div>
                <Button
                    type="submit"
                    disabled={form.processing}
                    aria-busy={form.processing}
                >
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
