<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Syncs one page of one run (PRD §10, §22). No logic: it calls SyncService for its run and page, which queues the next
 * page or completes the run. Unique per run and page (uniqueFor 600, PRD §10 idempotency #2). If the worker dies or the
 * page times out, failed() ends the still-running run — the service call is idempotent, so a page that already failed its
 * run itself is left alone.
 */
final class SyncPageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** A page is one Woo list request plus the refund reads of a few orders. Must stay below the queue's retry_after (90), or a running page would be re-reserved. */
    public int $timeout = 80;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $syncJobId,
        public readonly int $page,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return "{$this->syncJobId}:{$this->page}";
    }

    public function handle(SyncService $sync): void
    {
        $sync->runPage($this->syncJobId, $this->page);
    }

    public function failed(Throwable $exception): void
    {
        app(SyncService::class)->failRun($this->syncJobId, $exception, $this->page);
    }
}
