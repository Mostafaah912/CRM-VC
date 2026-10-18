<?php

declare(strict_types=1);

namespace App\Modules\Ai\Context;

use App\Modules\Ai\Dto\BuiltContext;
use App\Modules\Ai\Dto\ToolResult;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Tools\ToolRegistry;
use InvalidArgumentException;

/**
 * Builds the analyst's input (PRD §19: "ContextBuilder (tools, compact JSON, max 24KB)") by running the read-only tools
 * SERVER-SIDE — no LLM, no network, no budget. Every number is precomputed (period changes included); the model interprets.
 *
 * Sections, highest priority first: meta + freshness/data_quality (never cut) → kpis → anomalies → trend → customer_health
 * (RFM, churn, segments) → retention_cohort → products_affinity. If the compact JSON exceeds the cap, the LOWEST-priority section
 * still above its floor is reduced one step (fewer rows, then fewer parts, then removed) until it fits; `anomalies` is the last
 * to be touched and is only ever shortened, never removed; meta/freshness and the primary KPI window are never touched. Every
 * cut is listed in `truncations` and flagged `truncated: true`. If even that floor does not fit, ContextTooLargeException.
 *
 * Determinism: keys and rows are in a fixed order, nothing time-of-run is embedded, so the same data on the same day gives the
 * same bytes (P7-07's cache_key). The final JSON passes ContextPiiGuard; a hit throws, nothing polluted is ever returned.
 */
final class ContextBuilder
{
    /** Cut order, first = lowest priority. Value = number of reduction steps available (level 0 = full). */
    private const LADDER = ['products_affinity' => 3, 'retention_cohort' => 3, 'customer_health' => 3, 'trend' => 2, 'kpi_secondary' => 1, 'anomalies' => 3];

    private const ANOMALY_LIMITS = [10, 5, 3, 1];

    /** @var array<string, ToolResult> */
    private array $cache = [];

    /** @var array<string, ToolResult> */
    private array $used = [];

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly DataFreshnessReader $freshness,
        private readonly ContextPiiGuard $guard,
    ) {}

    public function build(AiInsightType $type, ?int $days = null): BuiltContext
    {
        $profile = match ($type) {
            AiInsightType::DailyBrief => ['kpi' => [$days ?? 1, 7], 'trend' => ['days' => 14, 'granularity' => 'day'], 'anomaly_days' => 3],
            AiInsightType::WeeklyReview => ['kpi' => [$days ?? 7, 28], 'trend' => ['days' => 56, 'granularity' => 'week'], 'anomaly_days' => 7],
            default => throw new InvalidArgumentException("ContextBuilder supports daily_brief and weekly_review only (got {$type->value})."),
        };

        if ($days !== null && ($days < 1 || $days > 90)) {
            throw new InvalidArgumentException('days must be between 1 and 90.');
        }

        $this->cache = [];
        $freshness = $this->freshness->read();
        $maxBytes = (int) floor(max(0.05, (float) config('ai.context.max_kb', 24)) * 1024);
        $levels = array_fill_keys(array_keys(self::LADDER), 0);
        $truncations = [];

        while (true) {
            $payload = $this->assemble($type, $profile, $freshness, $levels, $truncations);
            $json = $this->encode($payload);

            if (strlen($json) <= $maxBytes) {
                break;
            }

            $next = null;

            foreach (self::LADDER as $section => $max) {
                if ($levels[$section] < $max) {
                    $next = $section;

                    break;
                }
            }

            if ($next === null) {
                throw new ContextTooLargeException('The irreducible context ('.strlen($json)." bytes) exceeds the {$maxBytes}-byte limit.");
            }

            $levels[$next]++;
            $truncations[$next] = ['section' => $next, 'action' => $levels[$next] >= self::LADDER[$next] && $next !== 'anomalies' ? 'removed' : 'reduced', 'detail' => "level {$levels[$next]} of ".self::LADDER[$next]];
        }

        $this->guard->assertClean($payload);

        $size = strlen($json);

        return new BuiltContext(
            $type->value,
            $json,
            $size,
            $maxBytes,
            (int) ceil($size / max(0.5, (float) config('ai.context.bytes_per_token', 3.0))),
            $truncations !== [],
            array_values($truncations),
            array_map(fn ($section) => strlen($this->encode(is_array($section) ? $section : [$section])), $payload),
            array_values($this->used),
            (int) $payload['meta']['redactions'],
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $freshness
     * @param  array<string, int>  $levels
     * @param  array<string, array{section: string, action: string, detail: string}>  $truncations
     * @return array<string, mixed>
     */
    private function assemble(AiInsightType $type, array $profile, array $freshness, array $levels, array $truncations): array
    {
        $this->used = [];
        $unavailable = [];
        $run = function (string $tool, array $args = []) use (&$unavailable): ?array {
            $result = $this->run($tool, $args);

            if ($result->failed()) {
                $unavailable[] = $tool;

                return null;
            }

            return $result->result;
        };

        $kpiDays = $levels['kpi_secondary'] === 0 ? $profile['kpi'] : [$profile['kpi'][0]];
        $windows = [];

        foreach ($kpiDays as $i => $d) {
            $r = $run('get_dashboard_metrics', ['days' => $d]);

            if ($r !== null && $i > 0) {
                unset($r['customer_base']);
            }

            $windows[] = ['days' => $d, 'result' => $r];
        }

        $limit = self::ANOMALY_LIMITS[$levels['anomalies']];
        $anomalies = $run('get_anomalies', ['days' => $profile['anomaly_days'], 'limit' => $limit]);
        $gapCount = 0;

        if ($anomalies !== null) {
            $suspect = $freshness['stale_data'] === true || $freshness['metrics_failed_recently'] === true || $freshness['sync_failed_recently'] === true;
            $anomalies['anomalies'] = array_map(function (array $e) use ($suspect, &$gapCount) {
                $gap = $suspect && $e['observed'] === 0 && in_array($e['metric'], ['orders_count', 'net_revenue', 'customers_new'], true);
                $gapCount += $gap ? 1 : 0;

                return $e + ['suspected_data_gap' => $gap];
            }, $anomalies['anomalies']);
        }

        $sections = [];
        $sections['kpis'] = ['windows' => $windows];
        $sections['anomalies'] = $anomalies ?? ['unavailable' => true];

        if ($levels['trend'] < 2) {
            $trend = $profile['trend'];
            $trend['days'] = $levels['trend'] === 0 ? $trend['days'] : intdiv($trend['days'], 2);
            $sections['trend'] = $run('get_sales_trend', $trend) ?? ['unavailable' => true];
        }

        if ($levels['customer_health'] < 3) {
            $health = ['rfm' => $run('get_rfm_distribution'), 'churn' => $run('get_churn_report')];

            if ($levels['customer_health'] < 2) {
                $health['segments'] = $run('get_segment_summary', ['limit' => $levels['customer_health'] === 0 ? 10 : 5]);
            }

            if ($levels['customer_health'] === 0) {
                $health['customer_base'] = $run('get_customer_summary');
            }

            $sections['customer_health'] = $health;
        }

        if ($levels['retention_cohort'] < 3) {
            $big = $levels['retention_cohort'] === 0;
            $part = ['retention' => $run('get_retention_report', $big ? ['max_period' => 6, 'last_cohorts' => 12] : ['max_period' => 3, 'last_cohorts' => 6])];

            if ($levels['retention_cohort'] < 2) {
                $part['cohorts'] = $run('get_cohort_report', $big ? ['last_cohorts' => 6, 'max_period' => 6] : ['last_cohorts' => 3, 'max_period' => 3]);
            }

            $sections['retention_cohort'] = $part;
        }

        if ($levels['products_affinity'] < 3) {
            $l = $levels['products_affinity'];
            $part = ['top_products' => $run('get_top_products', ['limit' => [10, 5, 3][$l]])];

            if ($l < 2) {
                $part['affinity_product'] = $run('get_product_affinity', ['level' => 'product', 'limit' => [8, 4][$l]]);
            }

            if ($l === 0) {
                $part['affinity_category'] = $run('get_product_affinity', ['level' => 'category', 'limit' => 5]);
            }

            $sections['products_affinity'] = $part;
        }

        $unavailable = array_values(array_unique($unavailable));
        sort($unavailable);

        // Never silent: every KPI window and the trend must end on the SAME complete day the freshness block reports (Period::end is the
        // single anchor, so a mismatch means a bug or a tool reading a different day — which is exactly how the first real run compared
        // today's unfinished row with a complete day).
        $asOf = $freshness['as_of']['iso'] ?? null;
        $ends = array_values(array_filter(array_merge(
            array_map(fn (array $w) => $w['result']['period']['to']['iso'] ?? null, $windows),
            [$sections['trend']['to']['iso'] ?? null],
        )));
        $mismatch = $asOf !== null && array_filter($ends, fn (string $to) => $to !== $asOf) !== [];

        if ($mismatch) {
            $freshness['warnings'] = [...$freshness['warnings'], 'period_anchor_mismatch'];
        }

        $quality = $freshness + [
            'period_anchor' => ['as_of' => $asOf, 'kpi_and_trend_end' => array_values(array_unique($ends)), 'mismatch' => $mismatch],
            'suspected_data_gap_events' => $gapCount,
            'interpretation' => $gapCount > 0 || $freshness['warnings'] !== [] ? array_filter([
                'suspected_data_gap' => 'zero_values_may_be_missing_data_not_business_decline',
                'stale_data' => 'latest_numbers_may_not_include_recent_days',
                'period_anchor_mismatch' => $mismatch ? 'figures_may_describe_a_different_day_than_as_of_do_not_compare_them' : null,
                'product_coverage_low' => in_array('product_coverage_low', $freshness['warnings'], true) ? 'product_rankings_and_affinities_cover_only_part_of_product_revenue_state_this' : null,
            ]) : [],
            'unavailable_tools' => $unavailable,
        ];

        $meta = [
            'schema' => 'analyst.context.v1',
            'type' => $type->value,
            'currency' => 'IRT',
            'money' => 'integer_toman',
            'numbers_are_precomputed' => true,
            'primary_window_days' => $profile['kpi'][0],
        ];

        // Fixed key order; truncation is reported INSIDE the payload so the model knows the picture is partial.
        $ordered = ['meta' => $meta, 'data_quality' => $quality, 'truncated' => $truncations !== [], 'truncations' => array_values($truncations)] + $sections;
        $order = ['meta', 'data_quality', 'truncated', 'truncations', 'kpis', 'anomalies', 'trend', 'customer_health', 'retention_cohort', 'products_affinity'];
        $payload = [];

        foreach ($order as $key) {
            if (array_key_exists($key, $ordered)) {
                $payload[$key] = $ordered[$key];
            }
        }

        [$payload, $redactions] = $this->guard->sanitize($payload);
        $payload['meta']['redactions'] = count($redactions);

        return $payload;
    }

    /** @param array<string, mixed> $args */
    private function run(string $tool, array $args): ToolResult
    {
        ksort($args);
        $key = $tool.'|'.json_encode($args);
        $this->cache[$key] ??= $this->tools->execute($tool, $args);
        $this->used[$key] = $this->cache[$key];

        return $this->cache[$key];
    }

    /** @param array<array-key, mixed> $payload */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
