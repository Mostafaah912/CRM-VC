import { clvConfidences } from '@/lib/customer-labels';
import { formatToman } from '@/lib/format';

/**
 * customer_metrics' two CLV figures (PRD §13). Mandatory display contract: `estimated` is always
 * shown together with `confidence` — never a bare number. `estimated === null` reads as "داده کافی
 * نیست" (insufficient data), NEVER as zero — a customer with one order has no estimate yet, not a
 * worthless one. `confidence` is independent of `estimated`'s nullability (it reflects how much order
 * history exists, not whether a specific projection could be made), so it is shown even next to
 * "insufficient data" — "not enough data yet, low confidence" reads naturally together.
 * `historical` is always a real number (the column is NOT NULL) and is always labeled approximate:
 * it is revenue × margin_rate, never real profit (no cost data exists in Phase 1).
 */
type Props = {
    historical: number;
    estimated: number | null;
    confidence: string | null;
};

export function ClvValue({ historical, estimated, confidence }: Props) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col">
                <span className="text-muted-foreground text-xs">
                    ارزش طول عمر تاریخی (CLV)
                </span>
                <span className="text-lg font-medium">
                    {formatToman(historical)}
                </span>
                <span className="text-muted-foreground text-xs">
                    تقریبی — بر پایه حاشیه میانگین
                </span>
            </div>

            <div className="flex flex-col">
                <span className="text-muted-foreground text-xs">
                    ارزش طول عمر برآوردی (CLV)
                </span>
                {estimated === null ? (
                    <span className="text-muted-foreground text-lg font-medium">
                        داده کافی نیست
                    </span>
                ) : (
                    <span className="text-lg font-medium">
                        {formatToman(estimated)}
                    </span>
                )}
                {confidence !== null && (
                    <span className="text-muted-foreground text-xs">
                        {clvConfidences[confidence] ?? confidence}
                    </span>
                )}
            </div>
        </div>
    );
}
