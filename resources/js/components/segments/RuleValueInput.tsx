import { Input } from '@/components/ui/input';
import type { RuleScalarValue, RuleValue } from '@/types/segments';

type Props = {
    fieldName: string;
    value: RuleValue | null;
    onChange: (value: RuleValue | null) => void;
    valueShape: 'scalar' | 'list' | 'range' | 'none' | 'relative_days';
    maxRelativeDays?: number;
    'aria-invalid'?: boolean;
};

/** date input for `_at` fields (no Jalali date-picker exists in this project yet — ISO on the wire, converted server-side); number input for score/day/money-shaped fields; plain text otherwise (also covers behavior-field ids, since no product/category/segment picker exists yet either). */
function inputKindFor(fieldName: string): 'date' | 'number' | 'text' {
    if (fieldName.endsWith('_at')) {
        return 'date';
    }

    const numericFields = [
        'recency_days',
        'total_orders',
        'total_revenue',
        'monetary',
        'aov',
        'frequency',
        'r_score',
        'f_score',
        'm_score',
        'churn_risk_score',
        'clv_historical',
        'purchase_cycle_days',
        'product',
        'category',
        'variation',
        'segment',
    ];

    return numericFields.includes(fieldName) ? 'number' : 'text';
}

function toScalar(
    kind: 'date' | 'number' | 'text',
    raw: string,
): RuleScalarValue {
    return kind === 'number' && raw !== '' ? Number(raw) : raw;
}

function scalarToString(value: RuleScalarValue | undefined): string {
    return value === undefined ? '' : String(value);
}

/** One condition's value editor — the input shape (single/list/range/none) is decided by the operator's `valueShape`, sent from the backend whitelist, never guessed on the field alone. */
export function RuleValueInput({
    fieldName,
    value,
    onChange,
    valueShape,
    maxRelativeDays,
    'aria-invalid': ariaInvalid,
}: Props) {
    const kind = inputKindFor(fieldName);

    if (valueShape === 'none') {
        return null;
    }

    if (valueShape === 'relative_days') {
        const scalar = Array.isArray(value) ? '' : (value ?? '');

        return (
            <div className="flex items-center gap-2">
                <span className="text-muted-foreground text-sm">±</span>
                <Input
                    type="number"
                    min={0}
                    max={maxRelativeDays}
                    step={1}
                    aria-invalid={ariaInvalid}
                    placeholder="تعداد روز"
                    value={scalarToString(scalar)}
                    onChange={(e) => {
                        const raw = e.target.value;
                        onChange(raw === '' ? null : Number(raw));
                    }}
                />
                <span className="text-muted-foreground text-sm">
                    روز از امروز
                </span>
            </div>
        );
    }

    if (valueShape === 'list') {
        const list = Array.isArray(value) ? value : [];

        return (
            <Input
                type="text"
                dir="rtl"
                aria-invalid={ariaInvalid}
                placeholder="مقادیر را با کاما جدا کنید"
                value={list.join('، ')}
                onChange={(e) => {
                    const parts = e.target.value
                        .split(/[،,]/)
                        .map((p) => p.trim())
                        .filter((p) => p !== '');

                    onChange(
                        parts.length === 0
                            ? null
                            : parts.map((p) => toScalar(kind, p)),
                    );
                }}
            />
        );
    }

    if (valueShape === 'range') {
        const range = Array.isArray(value) ? value : [];

        return (
            <div className="flex items-center gap-2">
                <Input
                    type={kind === 'number' ? 'number' : kind}
                    aria-invalid={ariaInvalid}
                    placeholder="از"
                    value={scalarToString(range[0])}
                    onChange={(e) => {
                        const next: RuleScalarValue[] = [
                            toScalar(kind, e.target.value),
                            range[1] ?? '',
                        ];
                        onChange(next);
                    }}
                />
                <span className="text-muted-foreground text-sm">تا</span>
                <Input
                    type={kind === 'number' ? 'number' : kind}
                    aria-invalid={ariaInvalid}
                    placeholder="تا"
                    value={scalarToString(range[1])}
                    onChange={(e) => {
                        const next: RuleScalarValue[] = [
                            range[0] ?? '',
                            toScalar(kind, e.target.value),
                        ];
                        onChange(next);
                    }}
                />
            </div>
        );
    }

    const scalar = Array.isArray(value) ? '' : (value ?? '');

    return (
        <Input
            type={kind === 'number' ? 'number' : kind}
            dir={kind === 'text' ? 'rtl' : 'ltr'}
            aria-invalid={ariaInvalid}
            value={scalarToString(scalar)}
            onChange={(e) => {
                const raw = e.target.value;
                onChange(raw === '' ? null : toScalar(kind, raw));
            }}
        />
    );
}
