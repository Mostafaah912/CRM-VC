<?php

declare(strict_types=1);

namespace App\Modules\Ai\Context;

use App\Modules\Ai\Services\AnomalySource;
use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\ToolFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * "How much should the analyst trust today's numbers?" — read only from tables the hm_ai_readonly role may SELECT:
 *   - as_of / data_age_days: the newest COMPLETE day in daily_metrics vs yesterday (Tehran), via the same rule as the anomaly detector;
 *   - pipeline_last_run: max(daily_metrics.computed_at). The nightly chain is a Bus::chain that STOPS at the first failure
 *     (PRD §22), so when Sync fails daily_metrics is not rebuilt and this goes stale;
 *   - metrics runs: last finished run without an error, and whether the newest failed run is newer than it.
 * - Sync health is read from sync_cursors (granted to the role: entity, cursor_value, last_run_at, last_status,
 *   consecutive_failures, updated_at — no PII, no payload). A failed last run or consecutive_failures > 0 on ANY entity is
 *   `sync_failed_recently`.
 */
final class DataFreshnessReader
{
    public function __construct(private readonly AiReadonlyDb $db, private readonly AnomalySource $anomalies) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $connection = $this->db->connection();
        $report = $this->anomalies->report(1);
        $threshold = max(0, (int) config('ai.context.stale_after_days', 1));
        $age = $report->dataAgeDays;
        $stale = $age === null || $age > $threshold;

        $success = $connection->table('metric_runs')->whereNotNull('finished_at')->whereNull('error')->orderByDesc('id')->first(['id', 'mode', 'finished_at']);
        $failure = $connection->table('metric_runs')->whereNotNull('error')->orderByDesc('id')->first(['id', 'mode', 'finished_at', 'started_at']);
        $failedRecently = $failure !== null && ($success === null || $failure->id > $success->id);
        $pipeline = $connection->table('daily_metrics')->max('computed_at');

        $cursors = $connection->table('sync_cursors')->orderBy('entity')->get(['entity', 'cursor_value', 'last_run_at', 'last_status', 'consecutive_failures']);
        $sync = $cursors->map(fn ($c) => [
            'entity' => (string) $c->entity,
            'last_status' => $c->last_status === null ? null : (string) $c->last_status,
            'consecutive_failures' => (int) $c->consecutive_failures,
            'last_run' => $this->stamp($c->last_run_at),
            'cursor' => $this->stamp($c->cursor_value),
        ])->values()->all();
        $syncFailed = $cursors->contains(fn ($c) => (int) $c->consecutive_failures > 0 || $c->last_status === 'failed');

        $warnings = [];

        if ($stale) {
            $warnings[] = 'stale_data';
        }

        if ($failedRecently) {
            $warnings[] = 'metrics_run_failed_recently';
        }

        if ($syncFailed) {
            $warnings[] = 'sync_failed_recently';
        }

        // Share of product revenue that is attributed to a product (order lines without a product link never reach
        // customer_product_purchases). Lifetime on both sides; the AI role cannot read order_items, so this is the honest proxy.
        $attributed = (int) $connection->table('customer_product_purchases')->sum('revenue');
        $productRevenue = (int) $connection->table('daily_metrics')->sum('product_revenue');
        $coverage = $productRevenue > 0 ? ToolFormat::percent(min($attributed, $productRevenue), $productRevenue) : null;
        $minCoverage = (float) config('ai.context.min_product_coverage_percent', 90);

        $gaps = $this->dailyMetricsGaps($connection, $report->asOf);

        if ($gaps['missing_days'] > 0 || $gaps['zero_order_days'] >= max(1, (int) config('ai.context.zero_days_warn_at', 3))) {
            $warnings[] = 'daily_metrics_gap';
        }

        if ($coverage !== null && $minCoverage > 0 && $coverage < $minCoverage) {
            $warnings[] = 'product_coverage_low';
        }

        return [
            'as_of' => $report->asOf === null ? null : ToolFormat::date($report->asOf),
            'data_age_days' => $age,
            'stale_after_days' => $threshold,
            'stale_data' => $stale,
            'pipeline_last_run' => $this->stamp($pipeline),
            'metrics_last_success' => $success === null ? null : ['mode' => (string) $success->mode] + ($this->stamp($success->finished_at) ?? []),
            'metrics_last_failure' => $failure === null ? null : ['mode' => (string) $failure->mode] + ($this->stamp($failure->finished_at ?? $failure->started_at) ?? []),
            'metrics_failed_recently' => $failedRecently,
            'sync' => $sync,
            'sync_failed_recently' => $syncFailed,
            'sync_status_source' => 'sync_cursors',
            'daily_metrics_gaps' => $gaps,
            'product_coverage' => ['basis' => 'lifetime_product_revenue_attributed_to_a_product', 'attributed_percent' => $coverage, 'unattributed_percent' => $coverage === null ? null : round(100 - $coverage, 2), 'min_percent' => $minCoverage],
            'warnings' => $warnings,
        ];
    }

    /**
     * Days WITHOUT a daily_metrics row (and zero-order rows) inside the window the analysis leans on (the anomaly baseline: trend and
     * the previous KPI windows sit inside it). A missing day is not "no sales" — it is a day nobody computed — so any anomaly, change or
     * baseline built over a window with holes cannot be trusted as a business signal.
     *
     * @return array{window_days: int, window_from: string|null, window_to: string|null, missing_days: int, missing_ranges: list<array{from: string, to: string, days: int}>, zero_order_days: int}
     */
    private function dailyMetricsGaps(ConnectionInterface $db, ?string $asOf): array
    {
        $none = ['window_days' => 0, 'window_from' => null, 'window_to' => null, 'missing_days' => 0, 'missing_ranges' => [], 'zero_order_days' => 0];

        if ($asOf === null) {
            return $none;
        }

        $days = max(14, (int) config('ai.anomaly.window_days', 56));
        $end = CarbonImmutable::parse($asOf, 'Asia/Tehran');
        $first = $db->table('daily_metrics')->min('date');
        $from = $end->subDays($days - 1);

        if ($first === null) {
            return $none;
        }

        // The table's own history may start inside the window: days before its first row are "no history", not a hole.
        $from = $from->max(CarbonImmutable::parse((string) $first, 'Asia/Tehran'));
        $rows = $db->table('daily_metrics')->whereBetween('date', [$from->toDateString(), $end->toDateString()])->pluck('orders_count', 'date')->all();
        $ranges = [];
        $missing = 0;

        for ($d = $from; $d <= $end; $d = $d->addDay()) {
            if (array_key_exists($d->toDateString(), $rows)) {
                continue;
            }

            $missing++;
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last]['to'] === $d->subDay()->toDateString()) {
                $ranges[$last]['to'] = $d->toDateString();
                $ranges[$last]['days']++;
            } else {
                $ranges[] = ['from' => $d->toDateString(), 'to' => $d->toDateString(), 'days' => 1];
            }
        }

        return [
            'window_days' => (int) $from->diffInDays($end) + 1,
            'window_from' => $from->toDateString(),
            'window_to' => $end->toDateString(),
            'missing_days' => $missing,
            'missing_ranges' => array_slice($ranges, 0, 5),
            'zero_order_days' => count(array_filter($rows, fn ($n) => (int) $n === 0)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function stamp(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $utc = CarbonImmutable::parse((string) $value)->utc();
        $tehran = $utc->setTimezone('Asia/Tehran');

        return ['at_utc' => $utc->format('Y-m-d\TH:i:s\Z')] + ['tehran' => ToolFormat::date($tehran->toDateString()) + ['time' => $tehran->format('H:i')]];
    }
}
