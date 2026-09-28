<?php

declare(strict_types=1);

use App\Modules\Metrics\Enums\MetricRunMode;
use App\Modules\Metrics\Enums\MetricRunStatus;
use App\Modules\Metrics\Models\MetricRun;
use App\Modules\Metrics\Services\MetricRunService;
use Carbon\CarbonImmutable;

/*
| P6-10 additions to P4-01's MetricRunService, for HealthCheckService's MetricRunFailure and
| NightlyChainTimeout alerts (App\Support\HealthCheckService). Pure reads, no new writes.
*/

function mrRow(MetricRunStatus $status, string $startedAt, MetricRunMode $mode = MetricRunMode::Full): MetricRun
{
    return MetricRun::create([
        'mode' => $mode,
        'status' => $status,
        'started_at' => CarbonImmutable::parse($startedAt, 'UTC'),
        'finished_at' => $status === MetricRunStatus::Running ? null : CarbonImmutable::parse($startedAt, 'UTC')->addMinute(),
    ]);
}

it('latestRunFailed is true when the most recent run failed', function () {
    mrRow(MetricRunStatus::Completed, '2026-06-01 03:00:00');
    mrRow(MetricRunStatus::Failed, '2026-06-02 03:00:00');

    expect(app(MetricRunService::class)->latestRunFailed())->toBeTrue();
});

it('latestRunFailed is false when the most recent run succeeded, even if an earlier one failed', function () {
    mrRow(MetricRunStatus::Failed, '2026-06-01 03:00:00');
    mrRow(MetricRunStatus::Completed, '2026-06-02 03:00:00');

    expect(app(MetricRunService::class)->latestRunFailed())->toBeFalse();
});

it('latestRunFailed is false when no run has ever happened', function () {
    expect(app(MetricRunService::class)->latestRunFailed())->toBeFalse();
});

it('fullRunCompletedSince is true only for a completed full run at or after the given instant', function () {
    $since = CarbonImmutable::parse('2026-06-30 03:00:00', 'UTC');
    mrRow(MetricRunStatus::Completed, '2026-06-30 03:00:00', MetricRunMode::Full);

    expect(app(MetricRunService::class)->fullRunCompletedSince($since))->toBeTrue();
});

it('fullRunCompletedSince is false for a full run that started before the given instant', function () {
    $since = CarbonImmutable::parse('2026-06-30 03:00:00', 'UTC');
    mrRow(MetricRunStatus::Completed, '2026-06-29 03:00:00', MetricRunMode::Full);

    expect(app(MetricRunService::class)->fullRunCompletedSince($since))->toBeFalse();
});

it('fullRunCompletedSince is false for a dirty run, even a completed recent one', function () {
    $since = CarbonImmutable::parse('2026-06-30 03:00:00', 'UTC');
    mrRow(MetricRunStatus::Completed, '2026-06-30 04:00:00', MetricRunMode::Dirty);

    expect(app(MetricRunService::class)->fullRunCompletedSince($since))->toBeFalse();
});

it('fullRunCompletedSince is false for a full run that is still running or failed', function () {
    $since = CarbonImmutable::parse('2026-06-30 03:00:00', 'UTC');
    mrRow(MetricRunStatus::Running, '2026-06-30 04:00:00', MetricRunMode::Full);
    mrRow(MetricRunStatus::Failed, '2026-06-30 05:00:00', MetricRunMode::Full);

    expect(app(MetricRunService::class)->fullRunCompletedSince($since))->toBeFalse();
});
