<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncLogLevel;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Jobs\PruneLogsJob;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Models\SyncLog;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/*
| P6-10 — PruneLogsJob (PRD §22: "daily 04:30 PruneLogsJob"): thin wrapper around SyncService::pruneLogs(),
| no logic here (CLAUDE.md §1/§5). Retention window comes from config('woo.sync_log_retention_days'), never
| a literal in the job.
*/

it('implements ShouldBeUnique, on the sync queue, with a single attempt', function () {
    $job = new PruneLogsJob;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('sync')
        ->and($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('deletes sync_logs older than config(woo.sync_log_retention_days) when handled', function () {
    app()->instance(WooClient::class, new FakeWooClient([]));
    config(['woo.sync_log_retention_days' => 30]);
    $run = SyncJob::create([
        'entity' => SyncEntity::Orders, 'mode' => SyncMode::Incremental, 'status' => SyncStatus::Completed,
        'pages_processed' => 0, 'records_processed' => 0, 'records_failed' => 0, 'started_at' => now(),
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 00:00:00', 'UTC'));
    $old = SyncLog::create(['sync_job_id' => $run->id, 'level' => SyncLogLevel::Info, 'message' => 'x']);
    $old->created_at = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
    $old->save();
    $recent = SyncLog::create(['sync_job_id' => $run->id, 'level' => SyncLogLevel::Info, 'message' => 'y']);

    PruneLogsJob::dispatchSync();

    expect(SyncLog::whereKey($old->id)->exists())->toBeFalse()
        ->and(SyncLog::whereKey($recent->id)->exists())->toBeTrue();
});
