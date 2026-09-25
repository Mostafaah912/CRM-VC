import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { statusInfo } from '@/lib/system-status';
import type { StatusLabels, StatusTone } from '@/lib/system-status';

const toneClasses: Record<StatusTone, string> = {
    success:
        'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    warning:
        'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400',
    danger: 'border-transparent bg-red-500/15 text-red-700 dark:text-red-400',
    neutral: 'border-transparent bg-secondary text-secondary-foreground',
};

type Props = {
    status: string;
    labels: StatusLabels;
    className?: string;
};

export function StatusBadge({ status, labels, className }: Props) {
    const info = statusInfo(labels, status);

    return (
        <Badge
            variant="outline"
            className={cn(toneClasses[info.tone], className)}
        >
            {info.label}
        </Badge>
    );
}

/** The same tones for a label that is not a stored status (the GATE 1 verdict). */
export function ToneBadge({
    tone,
    children,
    className,
}: {
    tone: StatusTone;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Badge variant="outline" className={cn(toneClasses[tone], className)}>
            {children}
        </Badge>
    );
}
