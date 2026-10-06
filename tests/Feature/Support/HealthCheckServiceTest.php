<?php

declare(strict_types=1);

use App\Modules\Core\Enums\SettingKey;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\SettingService;
use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Models\MetricRun;
use App\Modules\Sync\Enums\ReconciliationStatus;
use App\Modules\Sync\Models\ReconciliationReportModel;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Support\HealthCheckService;
use Carbon\CarbonImmutable;

/*
| P6-10 — HealthCheckService: the 5 alert conditions PRD §22 lists that already have a real, existing
| signal to read (SyncFailure, MetricRunFailure, ReconciliationVariance, FailedJobsThreshold,
| NightlyChainTimeout), each raised through the existing AlertService (App\Modules\Core\Services\AlertService)
| — no parallel alert mechanism. AiBudgetThreshold is deliberately NOT checked here: BudgetGuard/the AI
| module do not exist yet (Sprint 7).
|
| Lives in App\Support (not a module): it is the one place allowed to read Sync's SyncHealthService AND
| Metrics' MetricRunService AND Core's AlertService/SettingService together — no module's PRD §07
| dependency list covers Sync+Metrics+Core at once, and the arch test "only reaches into other modules
| through their Services or Events" only scans app/Modules/**, so App\Support is the correct, and only
| architecturally valid, home for this composition (same reasoning as NightlyChainCommand, P6-09).
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    app()->instance(WooClient::class, new FakeWooClient([]));
});

function hcReport(string $month, string $diffPercent, ReconciliationStatus $status = ReconciliationStatus::Green): ReconciliationReportModel
{
    return ReconciliationReportModel::create([
        'jalali_month' => $month,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'woo_orders' => 100,
        'crm_orders' => 100,
        'woo_revenue' => 1_000_000,
        'crm_revenue' => 1_000_000,
        'orders_diff' => 0,
        'revenue_diff' => 0,
        'diff_percent' => $diffPercent,
        'status' => $status,
        'is_acceptable' => $status === ReconciliationStatus::Green,
        'reconciled_at' => now(),
    ]);
}

// ================================================================== SyncFailure

it('raises SyncFailure exactly at the configured consecutive-failures threshold, not below it', function () {
    app(SettingService::class)->set(SettingKey::AlertsConsecutiveSyncFailures, 3);
    SyncCursor::create(['entity' => 'orders', 'consecutive_failures' => 2]);

    expect(app(HealthCheckService::class)->check())->not->toContain('sync_failure');

    SyncCursor::whereKey('orders')->update(['consecutive_failures' => 3]);

    expect(app(HealthCheckService::class)->check())->toContain('sync_failure');
});

// ================================================================== MetricRunFailure

it('raises MetricRunFailure only when the most recent metrics run failed', function () {
    MetricRun::create(['mode' => MetricRunMode::Full, 'status' => MetricRunStatus::Completed, 'started_at' => now()]);
    expect(app(HealthCheckService::class)->check())->not->toContain('metric_run_failure');

    MetricRun::create(['mode' => MetricRunMode::Full, 'status' => MetricRunStatus::Failed, 'started_at' => now()]);
    expect(app(HealthCheckService::class)->check())->toContain('metric_run_failure');
});

// ================================================================== ReconciliationVariance

it('raises ReconciliationVariance only strictly above the configured percent, using the newest month', function () {
    app(SettingService::class)->set(SettingKey::AlertsReconciliationDiffPercent, 1.0);
    hcReport('1405-05', '1.0000', ReconciliationStatus::Red);

    expect(app(HealthCheckService::class)->check())->not->toContain('reconciliation_variance');

    hcReport('1405-06', '1.0001', ReconciliationStatus::Red);

    expect(app(HealthCheckService::class)->check())->toContain('reconciliation_variance');
});

it('does not raise ReconciliationVariance when no month has ever been reconciled', function () {
    expect(app(HealthCheckService::class)->check())->not->toContain('reconciliation_variance');
});

// ================================================================== FailedJobsThreshold

it('raises FailedJobsThreshold exactly at the configured count, not below it', function () {
    app(SettingService::class)->set(SettingKey::AlertsFailedJobsThreshold, 5);

    foreach (range(1, 4) as $i) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
            'payload' => '{}', 'exception' => '', 'failed_at' => now(),
        ]);
    }
    expect(app(HealthCheckService::class)->check())->not->toContain('failed_jobs_threshold');

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => '', 'failed_at' => now(),
    ]);
    expect(app(HealthCheckService::class)->check())->toContain('failed_jobs_threshold');
});

// ================================================================== NightlyChainTimeout

it('raises NightlyChainTimeout past 05:00 Tehran when no full run has completed since 03:00 today', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-30 01:35:00', 'UTC')); // Tehran 05:05

    expect(app(HealthCheckService::class)->check())->toContain('nightly_chain_timeout');
});

it('does not raise NightlyChainTimeout before the 05:00 Tehran deadline', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-29 23:59:00', 'UTC')); // Tehran 03:29

    expect(app(HealthCheckService::class)->check())->not->toContain('nightly_chain_timeout');
});

it('does not raise NightlyChainTimeout when tonight\'s full run already completed', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-30 00:00:00', 'UTC')); // Tehran 03:30 — chain running
    MetricRun::create(['mode' => MetricRunMode::Full, 'status' => MetricRunStatus::Completed, 'started_at' => now()]);
    test()->travelTo(CarbonImmutable::parse('2026-06-30 01:35:00', 'UTC')); // Tehran 05:05 — deadline

    expect(app(HealthCheckService::class)->check())->not->toContain('nightly_chain_timeout');
});

it('ignores a full run completed before tonight\'s chain window when checking NightlyChainTimeout', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-28 23:00:00', 'UTC')); // yesterday's chain
    MetricRun::create(['mode' => MetricRunMode::Full, 'status' => MetricRunStatus::Completed, 'started_at' => now()]);
    test()->travelTo(CarbonImmutable::parse('2026-06-30 01:35:00', 'UTC')); // today, past deadline

    expect(app(HealthCheckService::class)->check())->toContain('nightly_chain_timeout');
});

// ================================================================== AlertService reuse, not a parallel mechanism

it('goes through AlertService: an audit row is written and disabling alerts silences every condition', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-29 23:00:00', 'UTC')); // Tehran 03:30 — before the 05:00 deadline
    app(SettingService::class)->set(SettingKey::AlertsConsecutiveSyncFailures, 1);
    SyncCursor::create(['entity' => 'orders', 'consecutive_failures' => 1]);

    app(HealthCheckService::class)->check();
    expect(AuditLog::query()->where('action', 'alert.critical')->count())->toBe(1);

    app(SettingService::class)->set(SettingKey::AlertsEnabled, false);
    expect(app(HealthCheckService::class)->check())->toBe([]);
});
