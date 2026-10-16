<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Ai\Dto\BuiltContext;

/** A small, hand-made context (no database) and a matching valid analyst answer, for the P7-07 unit-level tests. */
final class AnalystSamples
{
    /** @return array<string, mixed> */
    public static function contextPayload(): array
    {
        return [
            'meta' => ['schema' => 'analyst.context.v1', 'type' => 'daily_brief', 'currency' => 'IRT', 'money' => 'integer_toman', 'numbers_are_precomputed' => true, 'primary_window_days' => 1, 'redactions' => 0],
            'data_quality' => ['as_of' => ['iso' => '2026-10-13', 'jalali' => '1405/07/21'], 'data_age_days' => 0, 'stale_data' => false, 'warnings' => [], 'suspected_data_gap_events' => 0],
            'truncated' => false,
            'truncations' => [],
            'kpis' => ['windows' => [['days' => 1, 'result' => [
                'current' => ['orders' => 44, 'net_revenue' => 8_800_000, 'aov' => 200_000],
                'previous' => ['orders' => 40, 'net_revenue' => 8_000_000, 'aov' => 200_000],
                'change_percent' => ['orders' => 10.0, 'net_revenue' => 10.0, 'aov' => 0.0],
            ]]]],
            'anomalies' => ['status' => 'ok', 'events_total' => 1, 'anomalies' => [[
                'metric' => 'orders_count', 'unit' => 'count', 'severity' => 'critical', 'direction' => 'up', 'observed' => 130, 'expected' => 43, 'suspected_data_gap' => false,
            ]]],
            'products_affinity' => ['top_products' => ['products' => [['product_id' => 1, 'name' => 'Linen Dress', 'buyers' => 6]]]],
        ];
    }

    public static function context(?array $payload = null): BuiltContext
    {
        $json = (string) json_encode($payload ?? self::contextPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new BuiltContext('daily_brief', $json, strlen($json), 24576, (int) ceil(strlen($json) / 3), false, [], ['meta' => 10], [], 0);
    }

    /** @return array<string, mixed> */
    public static function answer(): array
    {
        return [
            'headline' => 'فروش دیروز 10 درصد بیشتر از روز قبل بود',
            'summary' => 'درآمد خالص دیروز 8,800,000 تومان بود و سفارش‌ها از 40 به 44 رسید.',
            'key_metrics' => [['label' => 'درآمد خالص', 'value' => '8,800,000 تومان', 'change' => '+10.0%', 'metric_refs' => ['kpis.windows.0.result.current.net_revenue', 'kpis.windows.0.result.change_percent.net_revenue']]],
            'insights' => [
                ['text' => 'تعداد سفارش‌ها نسبت به روز قبل بیشتر شد.', 'kind' => 'fact', 'severity' => 'info', 'metric_refs' => ['kpis.windows.0.result.change_percent.orders']],
                ['text' => 'احتمالاً بخشی از رشد به یک کمپین مربوط است.', 'kind' => 'inference', 'severity' => 'info', 'metric_refs' => []],
            ],
            'risks' => [['text' => 'جهش ناگهانی سفارش‌ها باید بررسی شود.', 'severity' => 'warning', 'metric_refs' => ['anomalies.anomalies.0.observed']]],
            'opportunities' => [['text' => 'محصول پرفروش را در صفحه‌ی اصلی نمایش بده.', 'metric_refs' => ['products_affinity.top_products.products.0.buyers']]],
            'recommendations' => [['action' => 'موجودی محصول پرفروش را بررسی کن.', 'rationale' => 'تقاضا بالاست.', 'effort' => 'low', 'priority' => 'high']],
            'anomalies_explained' => [['ref' => 'anomalies.anomalies.0', 'interpretation' => 'جهش سفارش؛ علت نامشخص است.', 'is_data_issue' => false]],
            'data_gaps' => ['علت جهش سفارش‌ها در داده نیست.'],
            'data_quality_note' => '',
            'confidence' => 'medium',
        ];
    }
}
