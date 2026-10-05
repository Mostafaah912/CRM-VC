import { Head, Link } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { StatusBadge } from '@/components/status-badge';
import { useCan } from '@/hooks/use-can';
import {
    churnLevels,
    lifecycleStages,
    rfmSegments,
} from '@/lib/customer-labels';
import {
    formatNumber,
    formatPercent,
    formatRate,
    formatToman,
} from '@/lib/format';
import { dashboard } from '@/routes';
import { guide as metricsGuide } from '@/routes/metrics';
import { identityConflicts } from '@/routes/system';
import type { MetricsGuideData, RfmScoreRange } from '@/types/metrics-guide';

type Props = {
    data: MetricsGuideData;
};

const EMPTY = '—';
const SCORE_DIMENSIONS: {
    key: 'r_scores' | 'f_scores' | 'm_scores';
    label: string;
}[] = [
    { key: 'r_scores', label: 'تازگی (R) — روز از آخرین خرید' },
    { key: 'f_scores', label: 'تکرار (F) — تعداد سفارش' },
    { key: 'm_scores', label: 'ارزش (M) — تومان' },
];

/**
 * "X تا Y", in the same `<bdi dir="ltr">` isolation `date-range.tsx` already established: the ambient
 * RTL table cell reorders a plain `"484 تا 748"` string visually once it is wrapped as a single
 * LTR-dir block (the "تا" run gets pushed to the wrong end). Isolating only the numbers, inside RTL
 * flow, keeps the Persian word in its natural reading position either way. Only ever used for an
 * OBSERVED range (R, F, and M in 'lifetime' mode) — `max` is never null there, unlike M's
 * cut-point bands in 'recent_window' mode (see MonetaryBandText below).
 */
function RangeText({
    score,
}: {
    score: { min: number; max: number; customers: number };
}) {
    if (score.customers === 0) return <>{EMPTY}</>;
    if (score.min === score.max) {
        return <bdi dir="ltr">{formatNumber(score.min)}</bdi>;
    }

    return (
        <span dir="rtl">
            <bdi dir="ltr">{formatNumber(score.min)}</bdi> تا{' '}
            <bdi dir="ltr">{formatNumber(score.max)}</bdi>
        </span>
    );
}

/**
 * M's cut-point BAND in 'recent_window' mode — a defined threshold, not an observed min/max, and the
 * two end bands are deliberately open: band 1 is "تا X" (no real floor below 0), band 5 is "بیشتر از
 * X" (no ceiling). `min === 0` never happens for a real observed value here (customer_metrics.monetary_recent
 * is never exactly 0 for a counted purchase), so it unambiguously means "the open lower band".
 */
function MonetaryBandText({ band }: { band: RfmScoreRange }) {
    if (band.min === 0 && band.max !== null) {
        return (
            <span dir="rtl">
                تا <bdi dir="ltr">{formatToman(band.max)}</bdi>
            </span>
        );
    }

    if (band.max === null) {
        return (
            <span dir="rtl">
                بیشتر از <bdi dir="ltr">{formatToman(band.min)}</bdi>
            </span>
        );
    }

    return (
        <span dir="rtl">
            از <bdi dir="ltr">{formatToman(band.min)}</bdi> تا{' '}
            <bdi dir="ltr">{formatToman(band.max)}</bdi>
        </span>
    );
}

function percentage(count: number, total: number): string {
    return total === 0 ? '0%' : formatPercent(count / total);
}

export default function MetricsGuidePage({ data }: Props) {
    const can = useCan();
    const {
        rfm,
        clv,
        churn,
        lifecycle,
        cohort,
        affinity,
        dashboard: dashboardNote,
        data_quality: dataQuality,
    } = data;

    return (
        <>
            <Head title="راهنمای شاخص‌ها" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <header className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-1 rounded-xl border p-4">
                    <h1 className="text-2xl font-semibold">راهنمای شاخص‌ها</h1>
                    <p className="text-muted-foreground text-sm">
                        معنا، فرمول و عدد زنده‌ی هر شاخص HeyMode — هر عدد این
                        صفحه از همان منبعی خوانده می‌شود که موتور محاسبه می‌خواند.
                    </p>
                </header>

                {/* ===================================================================== RFM */}
                <Card id="rfm">
                    <CardHeader>
                        <CardTitle>RFM — تازگی، تکرار، ارزش</CardTitle>
                        <CardDescription>
                            هر مشتری در هر یک از سه بُعد امتیازی از 1 تا 5
                            می‌گیرد؛ ترکیب این سه امتیاز، سگمنت مشتری را تعیین
                            می‌کند.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        {rfm.monetary_mode === 'recent_window' && (
                            <div className="flex flex-wrap gap-4 rounded-lg border p-3 text-sm">
                                <span className="text-muted-foreground">
                                    محاسبه‌ی ارزش (M) تا تاریخ{' '}
                                    <bdi dir="ltr">
                                        {rfm.monetary_window_as_of ?? EMPTY}
                                    </bdi>
                                </span>
                                <span className="text-muted-foreground">
                                    آخرین بازمحاسبه:{' '}
                                    <bdi dir="ltr">
                                        {dataQuality.last_metrics_run_at ??
                                            EMPTY}
                                    </bdi>
                                </span>
                                <span className="text-muted-foreground">
                                    پنجره:{' '}
                                    {formatNumber(
                                        rfm.monetary_window_days ?? 0,
                                    )}{' '}
                                    روز اخیر
                                </span>
                            </div>
                        )}

                        <div>
                            <h3 className="mb-2 text-sm font-medium">
                                بازه‌ی واقعی هر امتیاز
                            </h3>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>بُعد</TableHead>
                                        {['1', '2', '3', '4', '5'].map(
                                            (label) => (
                                                <TableHead
                                                    key={label}
                                                    className="text-center"
                                                >
                                                    امتیاز {label}
                                                </TableHead>
                                            ),
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {SCORE_DIMENSIONS.filter(
                                        ({ key }) =>
                                            key !== 'm_scores' ||
                                            rfm.monetary_mode !==
                                                'recent_window',
                                    ).map(({ key, label }) => (
                                        <TableRow key={key}>
                                            <TableCell className="font-medium">
                                                {label}
                                            </TableCell>
                                            {rfm[key].map((score) => (
                                                <TableCell
                                                    key={score.score}
                                                    className="text-center"
                                                >
                                                    <div>
                                                        <RangeText
                                                            score={{
                                                                ...score,
                                                                max:
                                                                    score.max ??
                                                                    score.min,
                                                            }}
                                                        />
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {formatNumber(
                                                            score.customers,
                                                        )}{' '}
                                                        مشتری
                                                    </div>
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            {rfm.f_score_1_share !== null && (
                                <p className="text-muted-foreground mt-2 text-xs">
                                    حدود {formatPercent(rfm.f_score_1_share)}{' '}
                                    مشتریان دقیقاً یک سفارش ثبت کرده‌اند و امتیاز
                                    تکرار (F) آن‌ها 1 است — طبیعی است و نشانه‌ی
                                    خرابی نیست.
                                </p>
                            )}
                        </div>

                        {rfm.monetary_mode === 'recent_window' && (
                            <div>
                                <h3 className="mb-2 text-sm font-medium">
                                    بازه‌های ارزش (M) —{' '}
                                    {formatNumber(
                                        rfm.monetary_window_days ?? 0,
                                    )}{' '}
                                    روز اخیر
                                </h3>
                                {rfm.monetary_cutpoints_available ? (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="text-center">
                                                    امتیاز
                                                </TableHead>
                                                <TableHead>بازه</TableHead>
                                                <TableHead className="text-center">
                                                    تعداد مشتری
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {rfm.m_scores.map((band) => (
                                                <TableRow key={band.score}>
                                                    <TableCell className="text-center">
                                                        {band.score}
                                                    </TableCell>
                                                    <TableCell dir="ltr">
                                                        <MonetaryBandText
                                                            band={band}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        {formatNumber(
                                                            band.customers,
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <p className="text-muted-foreground text-sm">
                                        هنوز هیچ بازمحاسبه‌ای با این پنجره انجام
                                        نشده — بعد از اولین اجرا، بازه‌ها اینجا
                                        نمایش داده می‌شوند.
                                    </p>
                                )}
                                <div className="text-muted-foreground mt-3 flex flex-col gap-1 text-xs">
                                    <p>
                                        چرا فقط{' '}
                                        {formatNumber(
                                            rfm.monetary_window_days ?? 0,
                                        )}{' '}
                                        روز اخیر؟ ارزش تومانی سفارش‌های قدیمی‌تر
                                        به‌خاطر تورم چند سال اخیر، با ارزش امروز
                                        قابل‌مقایسه نیست؛ مقایسه‌ی مستقیم آن‌ها
                                        امتیاز را گمراه‌کننده می‌کرد.
                                    </p>
                                    <p>
                                        چرا بازه‌ها هم‌عرض نیستند؟ بازه‌ها از روی
                                        پراکندگی واقعی خریدهای همین دوره ساخته
                                        می‌شوند، نه با تقسیم مساوی؛ همین باعث
                                        می‌شود چند خریدار بزرگ، بازه‌ی بقیه را
                                        به‌هم نریزند.
                                    </p>
                                </div>
                            </div>
                        )}

                        <div>
                            <h3 className="mb-2 text-sm font-medium">
                                8 سگمنت RFM — از{' '}
                                {formatNumber(rfm.eligible_total)} مشتری دارای
                                امتیاز RFM
                            </h3>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>سگمنت</TableHead>
                                        <TableHead>شرط</TableHead>
                                        <TableHead className="text-center">
                                            تعداد مشتری
                                        </TableHead>
                                        <TableHead className="text-center">
                                            سهم
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rfm.segments.map((segment) => (
                                        <TableRow key={segment.segment}>
                                            <TableCell className="font-medium">
                                                {rfmSegments[segment.segment] ??
                                                    segment.segment}
                                            </TableCell>
                                            <TableCell
                                                dir="ltr"
                                                className="text-muted-foreground text-xs"
                                            >
                                                {segment.condition}
                                            </TableCell>
                                            <TableCell className="text-center">
                                                {formatNumber(
                                                    segment.customers,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-center">
                                                {percentage(
                                                    segment.customers,
                                                    rfm.eligible_total,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow>
                                        <TableCell className="font-medium">
                                            بدون امتیاز
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            هنوز هیچ سفارش محقق‌شده‌ای ندارد
                                        </TableCell>
                                        <TableCell className="text-center">
                                            {formatNumber(
                                                rfm.not_eligible_customers,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-center">
                                            —
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <div>
                            <h3 className="mb-2 text-sm font-medium">
                                {formatNumber(rfm.system_segments.length)} سگمنت
                                سیستمی
                            </h3>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>نام سگمنت</TableHead>
                                        <TableHead>شرح</TableHead>
                                        <TableHead className="text-center">
                                            تعداد عضو
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rfm.system_segments.map((segment) => (
                                        <TableRow key={segment.name}>
                                            <TableCell className="font-medium whitespace-nowrap">
                                                {segment.name}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm break-words whitespace-normal">
                                                {segment.sentence}
                                            </TableCell>
                                            <TableCell className="text-center">
                                                {formatNumber(segment.members)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* ===================================================================== CLV */}
                <Card id="clv">
                    <CardHeader>
                        <CardTitle>CLV — ارزش طول عمر مشتری</CardTitle>
                        <CardDescription>
                            تاریخی: مجموع سود واقعی تاکنون. تخمینی: برون‌یابی روی
                            افق {formatNumber(clv.horizon_years)} ساله، فقط برای
                            مشتریانی که حداقل{' '}
                            {formatNumber(clv.min_orders_for_estimate)} سفارش
                            دارند.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                نرخ حاشیه سود
                            </span>
                            <span className="text-lg font-medium">
                                {formatPercent(clv.margin_rate)}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                مشتری با CLV تاریخی
                            </span>
                            <span className="text-lg font-medium">
                                {formatNumber(clv.historical_customers)}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                مشتری با CLV تخمینی
                            </span>
                            <span className="text-lg font-medium">
                                {formatNumber(clv.estimated_customers)}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                آستانه اطمینان (کم / بالا)
                            </span>
                            <span className="text-lg font-medium" dir="ltr">
                                {formatNumber(clv.low_confidence_threshold)} /{' '}
                                {formatNumber(clv.high_confidence_threshold)}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                اطمینان کم
                            </span>
                            <span className="text-lg font-medium">
                                {formatNumber(clv.confidence_distribution.low)}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                اطمینان متوسط
                            </span>
                            <span className="text-lg font-medium">
                                {formatNumber(
                                    clv.confidence_distribution.medium,
                                )}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                اطمینان بالا
                            </span>
                            <span className="text-lg font-medium">
                                {formatNumber(clv.confidence_distribution.high)}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                {/* ===================================================================== Churn */}
                <Card id="churn">
                    <CardHeader>
                        <CardTitle>ریسک ریزش</CardTitle>
                        <CardDescription>
                            آستانه‌ها از چرخه‌ی خرید واقعی مشتریان محاسبه می‌شوند
                            (صدک 50/75/90 روز بین دو سفارش).
                            {churn.thresholds.is_fallback &&
                                ' نمونه فعلی کم است؛ آستانه‌های پیش‌فرض نمایش داده می‌شود.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    p50 (روز)
                                </span>
                                <span className="text-lg font-medium" dir="ltr">
                                    {formatNumber(churn.thresholds.p50)}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    p75 (روز)
                                </span>
                                <span className="text-lg font-medium" dir="ltr">
                                    {formatNumber(churn.thresholds.p75)}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    p90 (روز)
                                </span>
                                <span className="text-lg font-medium" dir="ltr">
                                    {formatNumber(churn.thresholds.p90)}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    حجم نمونه (حداقل{' '}
                                    {formatNumber(
                                        churn.low_sample_guard.minimum_sample,
                                    )}
                                    )
                                </span>
                                <span className="text-lg font-medium">
                                    {formatNumber(churn.thresholds.sample_size)}
                                </span>
                            </div>
                        </div>

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>سطح ریسک</TableHead>
                                    <TableHead className="text-center">
                                        تعداد مشتری
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {churn.levels.map((level) => (
                                    <TableRow key={level.level}>
                                        <TableCell>
                                            <StatusBadge
                                                status={level.level}
                                                labels={churnLevels}
                                            />
                                        </TableCell>
                                        <TableCell className="text-center">
                                            {formatNumber(level.customers)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow>
                                    <TableCell className="text-muted-foreground">
                                        بدون سفارش محقق‌شده (بدون ریسک محاسبه‌شده)
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {formatNumber(
                                            churn.no_orders_customers,
                                        )}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* ===================================================================== Lifecycle */}
                <Card id="lifecycle">
                    <CardHeader>
                        <CardTitle>مراحل چرخه‌ی عمر مشتری</CardTitle>
                        <CardDescription>
                            هر مشتری بر اساس سن، تعداد سفارش و فاصله از آخرین
                            خرید در یکی از این مراحل قرار می‌گیرد.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>مرحله</TableHead>
                                    <TableHead className="text-center">
                                        تعداد مشتری
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {lifecycle.stages.map((stage) => (
                                    <TableRow key={stage.stage}>
                                        <TableCell>
                                            <StatusBadge
                                                status={stage.stage}
                                                labels={lifecycleStages}
                                            />
                                        </TableCell>
                                        <TableCell className="text-center">
                                            {formatNumber(stage.customers)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* ===================================================================== Cohort / Retention */}
                <Card id="cohort">
                    <CardHeader>
                        <CardTitle>کوهورت و بازگشت مشتری</CardTitle>
                        <CardDescription>
                            هر کوهورت، ماه اولین خرید مشتریان است؛ «بالغ» یعنی
                            برای آن دوره، زمان کافی برای سنجش بازگشت گذشته است.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    تعداد کوهورت
                                </span>
                                <span className="text-lg font-medium">
                                    {formatNumber(cohort.cohort_months)}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    سلول بالغ
                                </span>
                                <span className="text-lg font-medium">
                                    {formatNumber(cohort.mature_cells)}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    سلول نابالغ
                                </span>
                                <span className="text-lg font-medium">
                                    {formatNumber(cohort.immature_cells)}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    مجموع مشتریان کوهورت‌ها
                                </span>
                                <span className="text-lg font-medium">
                                    {formatNumber(cohort.total_customers)}
                                </span>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    نرخ خرید تکراری —{' '}
                                    {formatNumber(
                                        cohort.retention.repeat_purchase_rate
                                            .repeat_customers,
                                    )}{' '}
                                    از{' '}
                                    {formatNumber(
                                        cohort.retention.repeat_purchase_rate
                                            .eligible_customers,
                                    )}{' '}
                                    مشتری
                                </span>
                                <span className="text-lg font-medium">
                                    {formatRate(
                                        cohort.retention.repeat_purchase_rate
                                            .rate,
                                    )}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">
                                    سهم درآمد بازگشتی —{' '}
                                    {formatToman(
                                        cohort.retention.returning_revenue_share
                                            .returning_revenue,
                                    )}{' '}
                                    از{' '}
                                    {formatToman(
                                        cohort.retention.returning_revenue_share
                                            .total_revenue,
                                    )}
                                </span>
                                <span className="text-lg font-medium">
                                    {formatRate(
                                        cohort.retention.returning_revenue_share
                                            .share,
                                    )}
                                </span>
                            </div>
                        </div>

                        <div>
                            <h3 className="mb-2 text-sm font-medium">
                                بازگشت N روزه
                            </h3>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>بازه</TableHead>
                                        <TableHead className="text-center">
                                            مشتری بالغ
                                        </TableHead>
                                        <TableHead className="text-center">
                                            بازگشته
                                        </TableHead>
                                        <TableHead className="text-center">
                                            نرخ بازگشت
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {cohort.retention.retention.map(
                                        (window) => (
                                            <TableRow key={window.days}>
                                                <TableCell>
                                                    {formatNumber(window.days)}{' '}
                                                    روزه
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {formatNumber(
                                                        window.mature_customers,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {formatNumber(
                                                        window.returned_customers,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {formatRate(
                                                        window.retention_rate,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* ===================================================================== Affinity */}
                <Card id="affinity">
                    <CardHeader>
                        <CardTitle>هم‌خرید محصولات (Affinity)</CardTitle>
                        <CardDescription>
                            هر جفت فقط وقتی ذخیره می‌شود که حداقل تعداد «مشتری
                            مشترک» را داشته باشد — تا جفت‌های تصادفی نمایش داده
                            نشوند.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>سطح</TableHead>
                                    <TableHead className="text-center">
                                        حداقل هم‌خرید
                                    </TableHead>
                                    <TableHead className="text-center">
                                        جفت ذخیره‌شده
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(
                                    [
                                        ['category', 'سطح دسته‌بندی'],
                                        ['product', 'سطح محصول'],
                                        ['variation', 'سطح تنوع'],
                                        ['basket', 'سطح سبد خرید'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <TableRow key={key}>
                                        <TableCell>{label}</TableCell>
                                        <TableCell className="text-center">
                                            {formatNumber(
                                                affinity.min_co_customers[key],
                                            )}
                                        </TableCell>
                                        <TableCell className="text-center">
                                            {formatNumber(
                                                affinity.pairs_stored[key],
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="flex flex-col gap-1 rounded-lg border p-3 sm:w-fit">
                            <span className="text-muted-foreground text-xs">
                                سهم اقلام سفارش متصل به یک محصول شناخته‌شده
                            </span>
                            <span className="text-lg font-medium">
                                {formatRate(
                                    affinity.order_items_resolved_percent,
                                )}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                {/* ===================================================================== Dashboard pointer */}
                <Card id="dashboard">
                    <CardHeader>
                        <CardTitle>شاخص‌های داشبورد</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-muted-foreground text-sm">
                            {dashboardNote.note}
                        </p>
                        {can('dashboard', 'view') && (
                            <Link
                                href={dashboard()}
                                className="mt-2 inline-block text-sm underline-offset-4 hover:underline"
                            >
                                مشاهده داشبورد
                            </Link>
                        )}
                    </CardContent>
                </Card>

                {/* ===================================================================== Data quality */}
                <Card id="data-quality">
                    <CardHeader>
                        <CardTitle>کیفیت داده</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                سهم اقلام سفارش متصل به یک محصول
                            </span>
                            <span className="text-lg font-medium">
                                {formatRate(
                                    dataQuality.order_items_resolved_percent,
                                )}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                تعارض هویت باز
                            </span>
                            {can('identity', 'review') ? (
                                <Link
                                    href={identityConflicts()}
                                    className="text-lg font-medium underline-offset-4 hover:underline"
                                >
                                    {formatNumber(
                                        dataQuality.open_identity_conflicts,
                                    )}
                                </Link>
                            ) : (
                                <span className="text-lg font-medium">
                                    {formatNumber(
                                        dataQuality.open_identity_conflicts,
                                    )}
                                </span>
                            )}
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                آخرین بازمحاسبه کامل
                            </span>
                            <span className="text-lg font-medium" dir="ltr">
                                {dataQuality.last_metrics_run_at ?? EMPTY}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded-lg border p-3">
                            <span className="text-muted-foreground text-xs">
                                حجم نمونه آستانه ریزش
                                {dataQuality.churn_threshold_is_fallback &&
                                    ' (پیش‌فرض)'}
                            </span>
                            <span className="text-lg font-medium">
                                {formatNumber(
                                    dataQuality.churn_threshold_sample_size,
                                )}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

MetricsGuidePage.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'راهنمای شاخص‌ها', href: metricsGuide() },
    ],
};
