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

    /**
     * A page is one Woo list request plus the refund reads of however many of its orders list one —
     * up to `woo.per_page` (50) sequential requests, each paying the rate limiter's spacing and, on a
     * 429, the full retry ladder (up to 120s on its own). The original 80s (sized for "a page is one
     * request plus a few refund reads") was found too tight on a real full sync: page 361 of a live
     * 513-page run hit it exactly and killed the run with 18,199 already-synced records stranded
     * behind an un-advanced cursor (P6-14 phase 4) — the same failure shape ReconcileMonthJob's own
     * 80s→300s fix already documents for a different job. Comfortably below the queue's retry_after
     * (930, P6-09), so a page already in flight is never re-reserved by another worker.
     */
    public int $timeout = 300;

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
