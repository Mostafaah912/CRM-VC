import { Head, router } from '@inertiajs/react';
import { AlertTriangle, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { BidiText } from '@/components/ai/bidi-text';
import { InsightView } from '@/components/ai/insight-view';
import { StatusBadge, ToneBadge } from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/hooks/use-can';
import { analystTypeLabels, confidenceLabels } from '@/lib/ai-labels';
import { formatNumber } from '@/lib/format';
import { READ_HEADERS, writeHeaders } from '@/lib/http';
import { dashboard } from '@/routes';
import { generate, history, index, status } from '@/routes/ai/analyst';
import type {
    AnalystInsight,
    AnalystPageData,
    AnalystType,
    GenerationState,
    HistoryPage,
} from '@/types/ai';

type Props = { data: AnalystPageData };

const TYPES: AnalystType[] = ['daily_brief', 'weekly_review'];
const POLL_MS = 3000;
const DISCLAIMER = 'تحلیل خودکار؛ پیش از تصمیم بررسی شود';

function isObject(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null;
}

function isGenerationState(v: unknown): v is GenerationState {
    return isObject(v) && typeof v.status === 'string';
}

function isHistoryPage(v: unknown): v is HistoryPage {
    return (
        isObject(v) &&
        Array.isArray(v.data) &&
        typeof v.page === 'number' &&
        typeof v.last_page === 'number'
    );
}

/** The server's refusal body: a stable code and a Persian sentence (never a key, prompt or response body). */
function refusalMessage(body: unknown, fallback: string): string {
    return isObject(body) && typeof body.message === 'string'
        ? body.message
        : fallback;
}

export default function Analyst({ data }: Props) {
    const can = useCan();
    const [tab, setTab] = useState<AnalystType>('daily_brief');
    const [states, setStates] = useState<Record<AnalystType, GenerationState>>(
        data.generation,
    );
    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [selected, setSelected] = useState<AnalystInsight | null>(null);
    const [hist, setHist] = useState<HistoryPage | null>(null);
    const [histPage, setHistPage] = useState(1);
    const [histError, setHistError] = useState(false);
    const previous = useRef<Record<AnalystType, GenerationState['status']>>({
        daily_brief: data.generation.daily_brief.status,
        weekly_review: data.generation.weekly_review.status,
    });

    const latest = data.latest[tab];
    const shown = selected ?? latest;
    const state = states[tab];
    const running = state.status === 'queued' || state.status === 'running';
    const canRequest = can('ai', 'request');
    const budgetBlocked = data.budget?.state === 'blocked';
    const throttled = data.manual.remaining <= 0;
    const generateDisabled =
        !data.enabled ||
        !data.readiness.ready ||
        running ||
        submitting ||
        throttled ||
        budgetBlocked;
    const stale = data.freshness.stale || (shown?.outdated ?? false);

    // Changing tab clears an old selection from the history.
    useEffect(() => {
        setSelected(null);
        setHistPage(1);
    }, [tab]);

    const loadHistory = useCallback(async () => {
        setHistError(false);

        try {
            const url = history.url({
                query: { type: tab, page: histPage, per_page: 5 },
            });
            const response = await fetch(url, {
                headers: READ_HEADERS,
                credentials: 'same-origin',
            });
            const body: unknown = await response.json();

            if (!response.ok || !isHistoryPage(body)) {
                throw new Error('bad history');
            }

            setHist(body);
        } catch {
            setHistError(true);
        }
    }, [tab, histPage]);

    useEffect(() => {
        void loadHistory();
    }, [
        loadHistory,
        data.latest.daily_brief?.id,
        data.latest.weekly_review?.id,
    ]);

    // Poll a queued/running generation until it finishes; then reload the page data once.
    useEffect(() => {
        const active = TYPES.filter(
            (t) =>
                states[t].status === 'queued' || states[t].status === 'running',
        );

        if (active.length === 0) {
            return;
        }

        const timer = window.setInterval(async () => {
            for (const type of active) {
                try {
                    const response = await fetch(
                        status.url({ query: { type } }),
                        {
                            headers: READ_HEADERS,
                            credentials: 'same-origin',
                        },
                    );
                    const body: unknown = await response.json();

                    if (response.ok && isGenerationState(body)) {
                        setStates((s) => ({ ...s, [type]: body }));
                    }
                } catch {
                    // a failed poll is retried on the next tick
                }
            }
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [states]);

    // React once to a run that just finished.
    useEffect(() => {
        for (const type of TYPES) {
            const before = previous.current[type];
            const now = states[type].status;

            if (
                before !== now &&
                (before === 'queued' || before === 'running')
            ) {
                if (now === 'done') {
                    toast.success('تحلیل تازه آماده شد');
                    router.reload({ only: ['data'] });
                } else if (now === 'failed') {
                    toast.error(
                        states[type].message ?? 'تولید تحلیل ناموفق بود',
                    );
                }
            }

            previous.current[type] = now;
        }
    }, [states]);

    async function submit() {
        setSubmitting(true);

        try {
            const response = await fetch(generate.url(), {
                method: 'POST',
                headers: writeHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ type: tab, force: latest !== null }),
            });
            const body: unknown = await response.json().catch(() => null);

            if (response.status === 202 && isGenerationState(body)) {
                setStates((s) => ({ ...s, [tab]: body }));
                setConfirming(false);
                toast.info(
                    body.already_running
                        ? 'یک تولید برای این تحلیل در حال انجام است'
                        : 'درخواست ثبت شد؛ تولید چند ده ثانیه طول می‌کشد',
                );

                return;
            }

            setConfirming(false);
            toast.error(
                refusalMessage(
                    body,
                    response.status === 403
                        ? 'دسترسی ندارید'
                        : 'درخواست انجام نشد',
                ),
            );
            router.reload({ only: ['data'] });
        } catch {
            toast.error('ارتباط با سرور برقرار نشد');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="تحلیلگر هوشمند" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-medium">تحلیلگر هوشمند</h1>
                    <ToneBadge tone="neutral">{DISCLAIMER}</ToneBadge>
                </div>

                {!data.enabled && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>تحلیلگر هوشمند غیرفعال است</AlertTitle>
                        <AlertDescription>
                            تا زمانی که مدیر سیستم آن را فعال نکند، تحلیل تازه
                            ساخته نمی‌شود؛ تحلیل‌های قبلی همچنان قابل مشاهده‌اند.
                        </AlertDescription>
                    </Alert>
                )}

                {data.enabled && !data.readiness.ready && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>تحلیلگر آماده نیست</AlertTitle>
                        <AlertDescription>
                            {data.readiness.message}
                        </AlertDescription>
                    </Alert>
                )}

                {data.freshness.stale && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>داده قدیمی</AlertTitle>
                        <AlertDescription>
                            آخرین داده کامل{' '}
                            {data.freshness.data_age_days === null
                                ? 'مشخص نیست'
                                : `${formatNumber(data.freshness.data_age_days)} روز پیش است`}
                            {data.freshness.as_of !== null && (
                                <>
                                    {' '}
                                    (<BidiText text={data.freshness.as_of} />)
                                </>
                            )}
                            ؛ تحلیل ممکن است وضعیت امروز را نشان ندهد.
                        </AlertDescription>
                    </Alert>
                )}

                {data.recent_problem !== null && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>مشکل اخیر</AlertTitle>
                        <AlertDescription>
                            {data.recent_problem.message} (
                            <BidiText text={data.recent_problem.at} />)
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>آخرین اجرا</CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {data.last_run === null ? (
                                'هنوز اجرایی ثبت نشده است'
                            ) : (
                                <>
                                    {analystTypeLabels[data.last_run.type] ??
                                        data.last_run.type}{' '}
                                    · <BidiText text={data.last_run.at} /> ·{' '}
                                    {data.last_run.manual ? 'دستی' : 'خودکار'}
                                </>
                            )}
                        </CardContent>
                    </Card>
                    {canRequest && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardDescription>
                                    درخواست‌های دستی
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="text-sm">
                                <bdi dir="ltr">
                                    {formatNumber(data.manual.remaining)}
                                </bdi>{' '}
                                از{' '}
                                <bdi dir="ltr">
                                    {formatNumber(data.manual.max_per_window)}
                                </bdi>{' '}
                                درخواست در{' '}
                                <bdi dir="ltr">
                                    {formatNumber(data.manual.window_minutes)}
                                </bdi>{' '}
                                دقیقه باقی مانده
                            </CardContent>
                        </Card>
                    )}
                    {data.budget && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardDescription>
                                    بودجه‌ی ماه ({data.budget.month})
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-1 text-sm">
                                <span>
                                    <bdi dir="ltr">
                                        ${data.budget.used_usd} / $
                                        {data.budget.cap_usd}
                                    </bdi>{' '}
                                    (
                                    <bdi dir="ltr">
                                        {data.budget.percent_usd}%
                                    </bdi>
                                    )
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    <bdi dir="ltr">
                                        {formatNumber(data.budget.requests)}
                                    </bdi>{' '}
                                    درخواست ·{' '}
                                    <bdi dir="ltr">
                                        {formatNumber(data.budget.used_tokens)}
                                    </bdi>{' '}
                                    توکن
                                </span>
                                {data.budget.state !== 'ok' && (
                                    <ToneBadge
                                        tone={
                                            data.budget.state === 'blocked'
                                                ? 'danger'
                                                : 'warning'
                                        }
                                    >
                                        {data.budget.state === 'blocked'
                                            ? 'بودجه تمام شده'
                                            : 'نزدیک سقف بودجه'}
                                    </ToneBadge>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex gap-2" role="tablist">
                        {TYPES.map((type) => (
                            <Button
                                key={type}
                                role="tab"
                                aria-selected={tab === type}
                                variant={tab === type ? 'default' : 'outline'}
                                onClick={() => setTab(type)}
                            >
                                {analystTypeLabels[type]}
                            </Button>
                        ))}
                    </div>

                    {canRequest && (
                        <Button
                            variant="outline"
                            disabled={generateDisabled}
                            onClick={() => setConfirming(true)}
                        >
                            {running ? <Spinner /> : <RefreshCw />}
                            {latest === null ? 'تولید تحلیل' : 'تولید دوباره'}
                        </Button>
                    )}
                </div>

                {running && (
                    <Alert>
                        <Spinner />
                        <AlertTitle>در حال تولید</AlertTitle>
                        <AlertDescription>
                            تولید تحلیل ممکن است تا یک دقیقه طول بکشد؛ این صفحه
                            خودکار به‌روز می‌شود.
                        </AlertDescription>
                    </Alert>
                )}

                {state.status === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>تولید ناموفق بود</AlertTitle>
                        <AlertDescription>{state.message}</AlertDescription>
                    </Alert>
                )}

                {throttled && canRequest && (
                    <p className="text-muted-foreground text-xs">
                        سقف درخواست دستی پر شده است؛ بعداً دوباره تلاش کنید.
                    </p>
                )}

                {shown === null ? (
                    <Card>
                        <CardContent className="text-muted-foreground py-10 text-center text-sm">
                            {data.enabled
                                ? `هنوز ${analystTypeLabels[tab]} ساخته نشده است.`
                                : 'هنوز تحلیلی ساخته نشده و تحلیلگر غیرفعال است.'}
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {selected !== null && (
                            <Alert>
                                <AlertTitle>تحلیل قدیمی‌تر</AlertTitle>
                                <AlertDescription>
                                    این تحلیل از تاریخچه انتخاب شده است.{' '}
                                    <Button
                                        variant="link"
                                        className="h-auto p-0"
                                        onClick={() => setSelected(null)}
                                    >
                                        بازگشت به آخرین تحلیل
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}
                        {stale && selected === null && shown.outdated && (
                            <ToneBadge tone="warning" className="w-fit">
                                داده قدیمی: روز تازه‌تری از این تحلیل در داده هست
                            </ToneBadge>
                        )}
                        <InsightView
                            insight={shown}
                            realizedStatuses={
                                data.definitions.realized_statuses
                            }
                        />
                    </>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            تاریخچه — {analystTypeLabels[tab]}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {histError && (
                            <p className="text-sm">
                                بارگذاری تاریخچه ناموفق بود.
                            </p>
                        )}
                        {hist !== null && hist.data.length === 0 && (
                            <p className="text-muted-foreground text-sm">
                                تاریخچه‌ای نیست.
                            </p>
                        )}
                        {hist?.data.map((item) => (
                            <button
                                key={item.id}
                                type="button"
                                onClick={() => setSelected(item)}
                                className="hover:bg-accent flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-start text-sm"
                            >
                                <span className="font-medium">
                                    <BidiText text={item.headline} />
                                </span>
                                <span className="text-muted-foreground flex items-center gap-2 text-xs">
                                    <BidiText text={item.created_at} />
                                    <StatusBadge
                                        status={item.confidence}
                                        labels={confidenceLabels}
                                    />
                                </span>
                            </button>
                        ))}
                        {hist !== null && hist.last_page > 1 && (
                            <div className="flex items-center justify-between pt-2 text-sm">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={hist.page <= 1}
                                    onClick={() => setHistPage(hist.page - 1)}
                                >
                                    قبلی
                                </Button>
                                <span>
                                    <bdi dir="ltr">
                                        {hist.page} / {hist.last_page}
                                    </bdi>
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={hist.page >= hist.last_page}
                                    onClick={() => setHistPage(hist.page + 1)}
                                >
                                    بعدی
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <p className="text-muted-foreground text-center text-xs">
                    {DISCLAIMER}
                </p>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            تولید {analystTypeLabels[tab]}
                        </DialogTitle>
                        <DialogDescription>
                            این کار از ارائه‌دهنده‌ی هوش مصنوعی استفاده می‌کند و
                            هزینه دارد و از بودجه‌ی ماهانه کم می‌شود. هر کاربر در
                            هر{' '}
                            <bdi dir="ltr">
                                {formatNumber(data.manual.window_minutes)}
                            </bdi>{' '}
                            دقیقه حداکثر{' '}
                            <bdi dir="ltr">
                                {formatNumber(data.manual.max_per_window)}
                            </bdi>{' '}
                            درخواست دستی دارد (الان{' '}
                            <bdi dir="ltr">
                                {formatNumber(data.manual.remaining)}
                            </bdi>{' '}
                            باقی مانده). تحلیل در پس‌زمینه ساخته می‌شود.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                        >
                            انصراف
                        </Button>
                        <Button
                            disabled={submitting}
                            onClick={() => void submit()}
                        >
                            {submitting && <Spinner />}
                            تأیید و تولید
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Analyst.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'تحلیلگر هوشمند', href: index() },
    ],
};
