<?php

declare(strict_types=1);

use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Enums\SettingKey;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\AlertService;
use App\Modules\Core\Services\SettingService;
use Illuminate\Support\Facades\Log;

it('raises a critical alert: logs at critical level and records an audit entry', function () {
    Log::spy();

    $raised = app(AlertService::class)->critical(AlertKind::SyncFailure, 'Orders sync failed twice', ['entity' => 'orders']);

    expect($raised)->toBeTrue();
    Log::shouldHaveReceived('critical')->once();

    $log = AuditLog::query()->where('action', 'alert.critical')->firstOrFail();

    expect($log->actor_type)->toBe(AuditActorType::System)
        ->and($log->after['kind'])->toBe('sync_failure')
        ->and($log->after['message'])->toBe('Orders sync failed twice')
        ->and($log->after['context'])->toBe(['entity' => 'orders']);
});

it('suppresses a duplicate alert of the same kind inside the dedupe window', function () {
    $service = app(AlertService::class);

    expect($service->critical(AlertKind::MetricRunFailure, 'first'))->toBeTrue()
        ->and($service->critical(AlertKind::MetricRunFailure, 'second'))->toBeFalse();

    expect(AuditLog::query()->where('action', 'alert.critical')->count())->toBe(1);
});

it('does not suppress a different kind of alert', function () {
    $service = app(AlertService::class);

    $service->critical(AlertKind::MetricRunFailure, 'a');

    expect($service->critical(AlertKind::SyncFailure, 'b'))->toBeTrue();
});

it('does nothing when alerts are disabled in settings', function () {
    app(SettingService::class)->set(SettingKey::AlertsEnabled, false);

    expect(app(AlertService::class)->critical(AlertKind::SyncFailure, 'x'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'alert.critical')->count())->toBe(0);
});

it('redacts secrets in alert context', function () {
    app(AlertService::class)->critical(AlertKind::SyncFailure, 'x', ['consumer_secret' => 'cs_live', 'entity' => 'orders']);

    $log = AuditLog::query()->where('action', 'alert.critical')->firstOrFail();

    expect($log->after['context']['consumer_secret'])->toBe('[REDACTED]')
        ->and($log->after['context']['entity'])->toBe('orders');
});

it('covers every alert condition named in the PRD', function () {
    expect(array_map(fn (AlertKind $k) => $k->value, AlertKind::cases()))->toEqualCanonicalizing([
        'sync_failure', 'metric_run_failure', 'reconciliation_variance',
        'failed_jobs_threshold', 'ai_budget_threshold', 'nightly_chain_timeout',
    ]);
});
