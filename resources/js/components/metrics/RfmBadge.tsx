import { Badge } from '@/components/ui/badge';
import { rfmSegments } from '@/lib/customer-labels';
import { cn } from '@/lib/utils';

/**
 * customer_metrics.rfm_segment as a colored badge (PRD §12). null means the customer is not
 * RFM-eligible (no orders, or deleted/non-active) — shown as a neutral "بدون امتیاز", never a
 * fabricated segment. Colors are per-segment (not the shared 4-tone StatusBadge system): champion
 * needs to read as more valuable than loyal, and lost more severe than cant_lose/at_risk.
 */
const SEGMENT_CLASSES: Record<string, string> = {
    champion:
        'border-transparent bg-emerald-600/20 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
    loyal: 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    promising:
        'border-transparent bg-blue-500/15 text-blue-700 dark:text-blue-400',
    new_customer:
        'border-transparent bg-sky-500/15 text-sky-700 dark:text-sky-400',
    at_risk:
        'border-transparent bg-orange-500/15 text-orange-700 dark:text-orange-400',
    cant_lose:
        'border-transparent bg-red-500/15 text-red-700 dark:text-red-400',
    hibernating: 'border-transparent bg-secondary text-secondary-foreground',
    lost: 'border-transparent bg-red-950/20 text-red-900 dark:bg-red-950/50 dark:text-red-300',
};

const NULL_CLASSES = 'border-transparent bg-muted text-muted-foreground';

type Props = {
    segment: string | null;
    className?: string;
};

export function RfmBadge({ segment, className }: Props) {
    if (segment === null) {
        return (
            <Badge variant="outline" className={cn(NULL_CLASSES, className)}>
                بدون امتیاز
            </Badge>
        );
    }

    return (
        <Badge
            variant="outline"
            className={cn(
                SEGMENT_CLASSES[segment] ??
                    'bg-secondary text-secondary-foreground border-transparent',
                className,
            )}
        >
            {rfmSegments[segment] ?? segment}
        </Badge>
    );
}
