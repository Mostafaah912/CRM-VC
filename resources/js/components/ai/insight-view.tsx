import { AlertTriangle, Info } from 'lucide-react';
import { BidiText } from '@/components/ai/bidi-text';
import { StatusBadge, ToneBadge } from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    adjustmentReasons,
    confidenceLabels,
    effortLabels,
    kindLabels,
    priorityLabels,
    severityLabels,
} from '@/lib/ai-labels';
import { formatNumber } from '@/lib/format';
import type { AnalystInsight } from '@/types/ai';

/** A change like "+20.57%" / "-14.89%": colour by direction, but the sign and the % are always part of the text, in an LTR isolate. */
function Change({ value }: { value: string }) {
    if (value === '') {
        return null;
    }

    const tone = value.startsWith('+')
        ? 'text-emerald-600 dark:text-emerald-400'
        : value.startsWith('-') || value.startsWith('−')
          ? 'text-red-600 dark:text-red-400'
          : 'text-muted-foreground';

    return (
        <bdi dir="ltr" className={`text-sm font-medium ${tone}`}>
            {value}
        </bdi>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

/**
 * One saved insight. Every string here is the model's text and is rendered as plain text (React escapes it) — there is no
 * raw-HTML injection and no markdown-to-HTML step anywhere. Numbers go through BidiText so signs and percents do not flip in RTL.
 */
export function InsightView({ insight }: { insight: AnalystInsight }) {
    const adjustment = insight.confidence_adjustment;
    const dateLabel =
        insight.period.start === insight.period.end
            ? insight.period.start
            : `${insight.period.start} تا ${insight.period.end}`;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-semibold">
                        <BidiText text={insight.headline} />
                    </h2>
                </div>
                <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                    <span>
                        دوره: <BidiText text={dateLabel ?? ''} />
                    </span>
                    <span>•</span>
                    <span>
                        ساخته‌شده: <BidiText text={insight.created_at} />
                    </span>
                    <span>•</span>
                    <span>{insight.manual ? 'درخواست دستی' : 'خودکار'}</span>
                    {insight.outdated && (
                        <ToneBadge tone="warning">داده قدیمی</ToneBadge>
                    )}
                    <StatusBadge
                        status={insight.confidence}
                        labels={confidenceLabels}
                    />
                </div>
            </div>

            {adjustment !== null && (
                <Alert>
                    <Info />
                    <AlertTitle>اطمینان کاهش یافت</AlertTitle>
                    <AlertDescription>
                        <p>
                            اطمینان از «
                            {confidenceLabels[adjustment.from]?.label ??
                                adjustment.from}
                            » به «
                            {confidenceLabels[adjustment.to]?.label ??
                                adjustment.to}
                            » رسید، به این دلیل:
                        </p>
                        <ul className="list-disc ps-5">
                            {adjustment.reasons.map((reason) => (
                                <li key={reason}>
                                    {adjustmentReasons[reason] ?? reason}
                                </li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}

            {insight.ungrounded_numbers.length > 0 && (
                <Alert>
                    <Info />
                    <AlertTitle>عدد بدون مرجع در داده</AlertTitle>
                    <AlertDescription>
                        {adjustmentReasons.ungrounded_numbers}:{' '}
                        <BidiText
                            text={insight.ungrounded_numbers.join('، ')}
                        />
                        . این عددها را پیش از استفاده بررسی کنید.
                    </AlertDescription>
                </Alert>
            )}

            <p className="text-sm leading-7">
                <BidiText text={insight.summary} />
            </p>

            {insight.key_metrics.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {insight.key_metrics.map((metric, i) => (
                        <Card key={i}>
                            <CardHeader className="pb-2">
                                <CardDescription>
                                    {metric.label}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex items-baseline justify-between gap-2">
                                <span className="text-xl font-semibold">
                                    <BidiText text={metric.value} />
                                </span>
                                <Change value={metric.change} />
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            {insight.insights.length > 0 && (
                <Section title="یافته‌ها">
                    <ul className="flex flex-col gap-3">
                        {insight.insights.map((item, i) => (
                            <li key={i} className="flex flex-col gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        status={item.severity}
                                        labels={severityLabels}
                                    />
                                    <Badge variant="outline">
                                        {kindLabels[item.kind] ?? item.kind}
                                    </Badge>
                                </div>
                                <p className="text-sm leading-7">
                                    <BidiText text={item.text} />
                                </p>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                {insight.risks.length > 0 && (
                    <Section title="ریسک‌ها">
                        <ul className="flex flex-col gap-3">
                            {insight.risks.map((risk, i) => (
                                <li key={i} className="flex flex-col gap-1">
                                    <StatusBadge
                                        status={risk.severity}
                                        labels={severityLabels}
                                    />
                                    <p className="text-sm leading-7">
                                        <BidiText text={risk.text} />
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}
                {insight.opportunities.length > 0 && (
                    <Section title="فرصت‌ها">
                        <ul className="flex flex-col gap-3">
                            {insight.opportunities.map((item, i) => (
                                <li key={i} className="text-sm leading-7">
                                    <BidiText text={item.text} />
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}
            </div>

            {insight.recommendations.length > 0 && (
                <Section title="پیشنهادها">
                    <ul className="flex flex-col gap-4">
                        {insight.recommendations.map((rec, i) => (
                            <li key={i} className="flex flex-col gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        status={rec.priority}
                                        labels={priorityLabels}
                                    />
                                    <Badge variant="outline">
                                        تلاش:{' '}
                                        {effortLabels[rec.effort] ?? rec.effort}
                                    </Badge>
                                </div>
                                <p className="text-sm leading-7 font-medium">
                                    <BidiText text={rec.action} />
                                </p>
                                <p className="text-muted-foreground text-sm leading-7">
                                    <BidiText text={rec.rationale} />
                                </p>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {insight.anomalies_explained.length > 0 && (
                <Section title="ناهنجاری‌ها">
                    <ul className="flex flex-col gap-2">
                        {insight.anomalies_explained.map((a, i) => (
                            <li key={i} className="text-sm leading-7">
                                <BidiText text={a.interpretation} />
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {insight.data_quality_note !== '' && (
                <Alert>
                    <AlertTriangle />
                    <AlertTitle>کیفیت داده</AlertTitle>
                    <AlertDescription>
                        <BidiText text={insight.data_quality_note} />
                    </AlertDescription>
                </Alert>
            )}

            {insight.data_gaps.length > 0 && (
                <Section title="آنچه داده پاسخ نمی‌دهد">
                    <ul className="list-disc ps-5 text-sm leading-7">
                        {insight.data_gaps.map((gap, i) => (
                            <li key={i}>
                                <BidiText text={gap} />
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {insight.technical && (
                <p className="text-muted-foreground text-xs" dir="ltr">
                    {insight.technical.provider}/{insight.technical.model} ·{' '}
                    {insight.technical.prompt_version} · in{' '}
                    {formatNumber(insight.technical.input_tokens)} / out{' '}
                    {formatNumber(insight.technical.output_tokens)} · $
                    {insight.technical.cost_usd}
                    {insight.technical.duration_ms !== null &&
                        ` · ${formatNumber(insight.technical.duration_ms)} ms`}
                </p>
            )}
        </div>
    );
}
