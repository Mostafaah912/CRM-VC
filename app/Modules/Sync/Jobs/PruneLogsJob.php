<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queues SyncService::pruneLogs() (PRD §22: "daily 04:30 PruneLogsJob") — a thin wrapper, no business logic
 * here (CLAUDE.md §1/§5). Retention window comes from config('woo.sync_log_retention_days'), a reasoned
 * default (PRD names the job but never a retention window — P6-10, ARCHITECTURE.md). No Log:: call here,
 * matching every other Sync job's convention: SyncService owns its own logging (sync_logs), never the
 * Log facade directly from a job (tests/Arch/SyncRunBoundaryTest.php).
 *
 * `tries = 1`, `ShouldBeUnique` + `uniqueFor > timeout` (PRD §22's rule for every whole-data job): a second
 * prune racing the first would just repeat the same chunked deletes — harmless but wasteful, not worth it.
 */
final class PruneLogsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'prune-logs';
    }

    public function handle(SyncService $sync): void
    {
        $sync->pruneLogs((int) config('woo.sync_log_retention_days'));
    }
}
