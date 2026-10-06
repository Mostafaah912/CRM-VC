<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Models\MetricRun;
use Carbon\CarbonImmutable;

/**
 * Lifecycle of one metric_runs row (PRD §11 step 1/12). The mode and status columns carry the CHECK
 * constraints; this service only ever writes the values their enums allow.
 */
final class MetricRunService
{
    public function start(MetricRunMode $mode): MetricRun
    {
        return MetricRun::create([
            'mode' => $mode,
            'status' => MetricRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function finish(MetricRun $run, int $customersProcessed): void
    {
        $run->update([
            'status' => MetricRunStatus::Completed,
            'customers_processed' => $customersProcessed,
            'finished_at' => now(),
        ]);
    }

    public function fail(MetricRun $run, string $error): void
    {
        $run->update([
            'status' => MetricRunStatus::Failed,
            'finished_at' => now(),
            'error' => $error,
        ]);
    }

    /** P6-10: true when the most recently started run ended failed — for HealthCheckService's MetricRunFailure alert. */
    public function latestRunFailed(): bool
    {
        return MetricRun::query()->latest('started_at')->latest('id')->value('status') === MetricRunStatus::Failed;
    }

    /** P6-10: true when a full run completed at or after $since — for HealthCheckService's NightlyChainTimeout alert. */
    public function fullRunCompletedSince(CarbonImmutable $since): bool
    {
        return MetricRun::query()
            ->where('mode', MetricRunMode::Full)
            ->where('status', MetricRunStatus::Completed)
            ->where('started_at', '>=', $since)
            ->exists();
    }
}
