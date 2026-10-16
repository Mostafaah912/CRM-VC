<?php

declare(strict_types=1);

namespace App\Modules\Ai\Context;

use App\Modules\Ai\Services\AnomalySource;
use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\ToolFormat;
use Carbon\CarbonImmutable;

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
            'warnings' => $warnings,
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
