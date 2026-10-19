import { Link } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { BidiText } from '@/components/ai/bidi-text';
import { StatusBadge, ToneBadge } from '@/components/status-badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { confidenceLabels } from '@/lib/ai-labels';
import { READ_HEADERS } from '@/lib/http';
import { index, widget } from '@/routes/ai/analyst';
import type { AnalystWidgetData } from '@/types/ai';

function isWidgetData(v: unknown): v is AnalystWidgetData {
    return (
        typeof v === 'object' &&
        v !== null &&
        'enabled' in v &&
        typeof v.enabled === 'boolean' &&
        'freshness' in v &&
        'readiness' in v
    );
}

/**
 * PRD §18 "خلاصه AI": the newest daily brief in short form, with its honesty flags, linking to the analyst page. Fetched ONCE on mount from its own
 * read-only endpoint (ai.view) — no polling, nothing is generated here. The headline is the model's text and is rendered as text only.
 */
export function AnalystWidget() {
    const [data, setData] = useState<AnalystWidgetData | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;

        async function load() {
            try {
                const response = await fetch(widget.url(), {
                    headers: READ_HEADERS,
                    credentials: 'same-origin',
                });
                const body: unknown = await response.json();

                if (!response.ok || !isWidgetData(body)) {
                    throw new Error('bad widget');
                }

                if (!cancelled) {
                    setData(body);
                }
            } catch {
                if (!cancelled) {
                    setFailed(true);
                }
            }
        }

        void load();

        return () => {
            cancelled = true;
        };
    }, []);

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between gap-2">
                <div>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Sparkles className="size-4" />
                        خلاصه‌ی تحلیلگر هوشمند
                    </CardTitle>
                    <CardDescription>
                        تحلیل خودکار؛ پیش از تصمیم بررسی شود
                    </CardDescription>
                </div>
                <Link
                    href={index().url}
                    className="text-sm underline underline-offset-4"
                >
                    صفحه‌ی تحلیلگر
                </Link>
            </CardHeader>
            <CardContent className="flex flex-col gap-2 text-sm">
                {failed && <p>بارگذاری خلاصه ناموفق بود.</p>}
                {!failed && data === null && (
                    <p className="text-muted-foreground">در حال بارگذاری…</p>
                )}
                {data !== null && !data.enabled && (
                    <p className="text-muted-foreground">
                        تحلیلگر هوشمند غیرفعال است.
                    </p>
                )}
                {data !== null && data.enabled && !data.readiness.ready && (
                    <p>{data.readiness.message}</p>
                )}
                {data !== null && data.latest === null && (
                    <p className="text-muted-foreground">
                        هنوز تحلیلی ساخته نشده است.
                    </p>
                )}
                {data?.latest && (
                    <>
                        <p className="leading-7 font-medium">
                            <BidiText text={data.latest.headline} />
                        </p>
                        <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                            <span>
                                دوره:{' '}
                                <BidiText text={data.latest.period.end ?? ''} />
                            </span>
                            <span>
                                ساخته‌شده:{' '}
                                <BidiText text={data.latest.created_at} />
                            </span>
                            <StatusBadge
                                status={data.latest.confidence}
                                labels={confidenceLabels}
                            />
                            {data.latest.confidence_reduced && (
                                <ToneBadge tone="warning">
                                    اطمینان کاهش یافت
                                </ToneBadge>
                            )}
                            {(data.latest.outdated || data.freshness.stale) && (
                                <ToneBadge tone="warning">داده قدیمی</ToneBadge>
                            )}
                            {data.freshness.orders_sync_behind && (
                                <ToneBadge tone="danger">
                                    همگام‌سازی سفارش‌ها عقب است
                                    {data.freshness.orders_behind_hours !==
                                        null && (
                                        <>
                                            {' '}
                                            (
                                            <bdi dir="ltr">
                                                {
                                                    data.freshness
                                                        .orders_behind_hours
                                                }
                                            </bdi>{' '}
                                            ساعت)
                                        </>
                                    )}
                                </ToneBadge>
                            )}
                        </div>
                    </>
                )}
                {data?.recent_problem && (
                    <p className="text-muted-foreground text-xs">
                        مشکل اخیر: {data.recent_problem.message}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
