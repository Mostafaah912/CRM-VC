import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BidiText } from '@/components/ai/bidi-text';
import { formatNumber, formatToman } from '@/lib/format';
import { READ_HEADERS } from '@/lib/http';
import { index } from '@/routes/ai/analyst';
import { analystPanel } from '@/routes/customers';
import { show as segmentShow } from '@/routes/segments';
import type { CustomerAnalystPanel as PanelData } from '@/types/ai';

type Props = { customerId: number };

function isPanel(v: unknown): v is PanelData {
    return (
        typeof v === 'object' &&
        v !== null &&
        'customer_id' in v &&
        typeof v.customer_id === 'number' &&
        'segments' in v &&
        'latest_insight' in v
    );
}

/**
 * Customer 360, aggregate side panel: which segments this customer is in (with each SEGMENT's own count and average recency/monetary) and a link to
 * the latest analyst insight. Nothing here is about this customer's own values, nothing is sent to an AI provider and no AI call is made by this
 * panel. Fetched once on mount; a part the viewer may not see (segments / insight) simply does not come back.
 */
export function CustomerAnalystPanel({ customerId }: Props) {
    const [data, setData] = useState<PanelData | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;

        async function load() {
            try {
                const response = await fetch(analystPanel(customerId).url, {
                    headers: READ_HEADERS,
                    credentials: 'same-origin',
                });
                const body: unknown = await response.json();

                if (!response.ok || !isPanel(body)) {
                    throw new Error('bad panel');
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
    }, [customerId]);

    if (failed) {
        return <p className="text-sm">بارگذاری ناموفق بود.</p>;
    }

    if (data === null) {
        return (
            <p className="text-muted-foreground text-sm">در حال بارگذاری…</p>
        );
    }

    return (
        <div className="flex flex-col gap-3 text-sm">
            {data.segments !== null && (
                <div className="flex flex-col gap-2">
                    <span className="text-muted-foreground text-xs">
                        سگمنت‌ها و آمار هر سگمنت (نه مقدار این مشتری)
                    </span>
                    {data.segments.length === 0 && (
                        <p className="text-muted-foreground">
                            این مشتری در هیچ سگمنت فعالی نیست.
                        </p>
                    )}
                    {data.segments.map((segment) => (
                        <div
                            key={segment.id}
                            className="flex flex-col gap-0.5 rounded-md border p-2"
                        >
                            <Link
                                href={segmentShow(segment.id).url}
                                className="font-medium underline underline-offset-4"
                            >
                                {segment.name}
                            </Link>
                            <span className="text-muted-foreground text-xs">
                                <bdi dir="ltr">
                                    {formatNumber(segment.members)}
                                </bdi>{' '}
                                عضو
                                {segment.avg_recency_days !== null && (
                                    <>
                                        {' '}
                                        · میانگین فاصله از آخرین خرید{' '}
                                        <bdi dir="ltr">
                                            {formatNumber(
                                                segment.avg_recency_days,
                                            )}
                                        </bdi>{' '}
                                        روز
                                    </>
                                )}
                                {segment.avg_monetary !== null && (
                                    <>
                                        {' '}
                                        · میانگین ارزش{' '}
                                        <bdi dir="ltr">
                                            {formatToman(segment.avg_monetary)}
                                        </bdi>
                                    </>
                                )}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            {data.latest_insight !== null && (
                <div className="flex flex-col gap-1">
                    <span className="text-muted-foreground text-xs">
                        آخرین تحلیل هوشمند
                    </span>
                    <Link
                        href={index().url}
                        className="underline underline-offset-4"
                    >
                        <BidiText text={data.latest_insight.headline} />
                    </Link>
                    <span className="text-muted-foreground text-xs">
                        <BidiText text={data.latest_insight.created_at} />
                    </span>
                </div>
            )}
            {data.latest_insight === null && data.segments === null && (
                <p className="text-muted-foreground">
                    برای این بخش دسترسی ندارید.
                </p>
            )}
        </div>
    );
}
