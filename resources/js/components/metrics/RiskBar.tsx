import { StatusBadge } from '@/components/status-badge';
import { churnLevels } from '@/lib/customer-labels';
import { cn } from '@/lib/utils';

/**
 * customer_metrics' churn risk score/level/reason (PRD §14), plus the estimated next order date.
 * `level === null` means the customer is not churn-eligible (no orders yet) — the whole section is
 * hidden, never a fabricated "low" risk. `churn_reason` is mandatory whenever `level` is set
 * (CLAUDE.md §4: a risk number without a reason is not trusted) and is shown as plain Persian text —
 * it already carries no PII (P4-05: only recency_days/purchase_cycle_days/p75 ever appear in it).
 * The next-order line only shows when that date is still in the future (compared via the ISO field,
 * never the Jalali display string) — a customer already overdue does not need a stale "next order" date.
 */
const LEVEL_BAR_COLOR: Record<string, string> = {
    low: 'bg-emerald-500',
    medium: 'bg-amber-500',
    high: 'bg-orange-500',
    lost: 'bg-red-600',
};

type Props = {
    /** numeric(5,2) on a 0..100 scale, as an exact decimal string. */
    score: string | null;
    level: string | null;
    reason: string | null;
    nextOrderAtJalali: string | null;
    nextOrderAtIso: string | null;
};

export function RiskBar({
    score,
    level,
    reason,
    nextOrderAtJalali,
    nextOrderAtIso,
}: Props) {
    if (level === null) {
        return null;
    }

    const numericScore =
        score === null ? 0 : Math.min(100, Math.max(0, Number(score)));
    const nextOrderIsFuture =
        nextOrderAtIso !== null && new Date(nextOrderAtIso) > new Date();

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
                <StatusBadge status={level} labels={churnLevels} />
                <div className="bg-muted h-2 w-full max-w-xs overflow-hidden rounded-full">
                    <div
                        className={cn(
                            'h-full rounded-full',
                            LEVEL_BAR_COLOR[level] ?? 'bg-muted-foreground',
                        )}
                        style={{ width: `${numericScore}%` }}
                    />
                </div>
                {score !== null && (
                    <span className="text-muted-foreground text-sm" dir="ltr">
                        {score}
                    </span>
                )}
            </div>

            {reason !== null && reason !== '' && (
                <p className="text-muted-foreground text-sm">{reason}</p>
            )}

            {nextOrderIsFuture && (
                <p className="text-muted-foreground text-sm">
                    خرید بعدی تخمینی: <span dir="ltr">{nextOrderAtJalali}</span>
                </p>
            )}
        </div>
    );
}
