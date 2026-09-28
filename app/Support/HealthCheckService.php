<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Enums\SettingKey;
use App\Modules\Core\Services\AlertService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Metrics\Services\MetricRunService;
use App\Modules\Sync\Services\SyncHealthService;
use App\Modules\Sync\Services\SyncService;
use App\Modules\Sync\Support\ReconciliationMonthSummary;
use Carbon\CarbonImmutable;

/**
 * PRD §22's "every 15m: ... HealthCheckJob" (P6-10): checks the 5 alert conditions that already have a
 * real, existing signal to read, and raises each through the existing AlertService — no parallel alert
 * mechanism. AiBudgetThreshold is deliberately not checked: BudgetGuard/the AI module do not exist yet
 * (Sprint 7) — see ARCHITECTURE.md's Open Items.
 *
 * Lives outside app/Modules on purpose: it reads Sync (SyncHealthService/SyncService), Metrics
 * (MetricRunService) and Core (AlertService/SettingService) together, and no module's PRD §07 dependency
 * list covers Sync+Metrics+Core at once. The arch test enforcing "only reach another module through its Services or Events" scans
 * only app/Modules/**, so App\Support is the one place this composition is architecturally valid — the
 * same reasoning already used for NightlyChainCommand (P6-09).
 */
final class HealthCheckService
{
    /** Matches the nightly chain's own schedule (routes/console.php, P6-09). */
    private const CHAIN_START_HOUR = 3;

    private const CHAIN_DEADLINE_HOUR = 5;

    public function __construct(
        private readonly SyncHealthService $syncHealth,
        private readonly SyncService $sync,
        private readonly MetricRunService $metricRuns,
        private readonly AlertService $alerts,
        private readonly SettingService $settings,
    ) {}

    /** @return list<string> the AlertKind values actually raised (not suppressed by dedupe or a disabled setting) */
    public function check(): array
    {
        $raised = [];

        $this->checkSyncFailure($raised);
        $this->checkMetricRunFailure($raised);

        $snapshot = $this->syncHealth->snapshot();
        $this->checkFailedJobsThreshold($raised, $snapshot->failedJobs);
        $this->checkReconciliationVariance($raised, $snapshot->recentMonths[0] ?? null);

        $this->checkNightlyChainTimeout($raised);

        return $raised;
    }

    /** @param  list<string>  $raised */
    private function checkSyncFailure(array &$raised): void
    {
        $failures = $this->sync->ordersConsecutiveFailures();
        $threshold = (int) $this->settings->get(SettingKey::AlertsConsecutiveSyncFailures);

        if ($failures >= $threshold) {
            $this->raise($raised, AlertKind::SyncFailure, "Orders sync failed {$failures} times in a row", ['consecutive_failures' => $failures]);
        }
    }

    /** @param  list<string>  $raised */
    private function checkMetricRunFailure(array &$raised): void
    {
        if ($this->metricRuns->latestRunFailed()) {
            $this->raise($raised, AlertKind::MetricRunFailure, 'The latest metrics recompute run failed');
        }
    }

    /** @param  list<string>  $raised */
    private function checkFailedJobsThreshold(array &$raised, ?int $failedJobs): void
    {
        $threshold = (int) $this->settings->get(SettingKey::AlertsFailedJobsThreshold);

        if ($failedJobs !== null && $failedJobs >= $threshold) {
            $this->raise($raised, AlertKind::FailedJobsThreshold, "{$failedJobs} jobs in the failed-jobs queue", ['failed_jobs' => $failedJobs]);
        }
    }

    /**
     * @param  list<string>  $raised
     *
     * Only the newest month: an already-known-red older month would otherwise re-raise every 15 minutes
     * alongside it, and AlertService's dedupe only distinguishes by kind, not by kind+month.
     */
    private function checkReconciliationVariance(array &$raised, ?ReconciliationMonthSummary $latest): void
    {
        if ($latest === null || $latest->diffPercent === null) {
            return;
        }

        $threshold = (float) $this->settings->get(SettingKey::AlertsReconciliationDiffPercent);

        if ((float) $latest->diffPercent > $threshold) {
            $this->raise($raised, AlertKind::ReconciliationVariance, "{$latest->month} reconciliation variance {$latest->diffPercent}%", [
                'month' => $latest->month,
                'diff_percent' => $latest->diffPercent,
            ]);
        }
    }

    /**
     * @param  list<string>  $raised
     *
     * "زنجیره شبانه تا ۵ صبح تمام نشد" (PRD §22): the nightly chain (P6-09) starts 03:00 Tehran; this checks
     * only whether its metrics step (RecomputeMetricsJob('full'), the earliest step every later step in the
     * chain depends on) completed — a later step failing after that is not caught by this specific check,
     * a documented, narrower limitation (no dedicated "chain run" table exists to track the whole chain,
     * and PRD gives no schema for one).
     */
    private function checkNightlyChainTimeout(array &$raised): void
    {
        $now = CarbonImmutable::now('Asia/Tehran');
        $deadline = $now->setTime(self::CHAIN_DEADLINE_HOUR, 0);

        if ($now->lessThan($deadline)) {
            return;
        }

        $chainStart = $now->setTime(self::CHAIN_START_HOUR, 0)->utc();

        if (! $this->metricRuns->fullRunCompletedSince($chainStart)) {
            $this->raise($raised, AlertKind::NightlyChainTimeout, 'The nightly chain has not completed its metrics step by 05:00 Tehran');
        }
    }

    /**
     * @param  list<string>  $raised
     * @param  array<string, mixed>  $context
     */
    private function raise(array &$raised, AlertKind $kind, string $message, array $context = []): void
    {
        if ($this->alerts->critical($kind, $message, $context)) {
            $raised[] = $kind->value;
        }
    }
}
