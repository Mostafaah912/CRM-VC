<?php

declare(strict_types=1);

use App\Jobs\HealthCheckJob;
use App\Modules\Core\Enums\SettingKey;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\SettingService;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/*
| P6-10 — HealthCheckJob (PRD §22: "every 15m: ..., HealthCheckJob"): a thin wrapper around
| App\Support\HealthCheckService::check(), no logic here (CLAUDE.md §1/§5).
*/

it('implements ShouldBeUnique, on the default queue, with a single attempt', function () {
    $job = new HealthCheckJob;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('default')
        ->and($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('raises a real alert through HealthCheckService when handled', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-30 00:00:00', 'UTC')); // Tehran 03:30, before the deadline
    config(['logging.default' => 'null']);
    app()->instance(WooClient::class, new FakeWooClient([]));
    app(SettingService::class)->set(SettingKey::AlertsConsecutiveSyncFailures, 1);
    SyncCursor::create(['entity' => 'orders', 'consecutive_failures' => 1]);

    HealthCheckJob::dispatchSync();

    expect(AuditLog::query()->where('action', 'alert.critical')->count())->toBe(1);
});
