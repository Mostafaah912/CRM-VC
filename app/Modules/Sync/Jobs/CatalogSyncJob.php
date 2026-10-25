<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Services\CatalogSyncCheckpoint;
use App\Modules\Sync\Services\CatalogSyncService;
use App\Modules\Sync\Support\SkipWhenWooIsNotConfigured;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * One catalog run — categories, then products + variations through `CatalogSyncService` (no logic here, one call). Since P8-03g it is NOT a
 * step of the nightly chain: it has its own schedule (01:00 Tehran — incremental daily, a FULL rebuild weekly on Friday), so a slow or
 * failing Woo can never stop the metrics chain, and the chain can never be starved by it.
 *
 * Incremental + resumable (see CatalogSyncService / CatalogSyncCheckpoint): products modified since the last completed run are read, each
 * fully written page is checkpointed, and a run that is killed or fails continues at the page it reached instead of page 1. `$full` asks
 * for the full rebuild.
 *
 * Why it used to stop the chain every few nights (P7-08 follow-up, measured live): the catalog is ~311 sequential Woo requests (56 product
 * pages + one /variations call per variable product), and Woo answers ANY request in 2.3-4 s (a one-row orders call included), so a full
 * read is ~900 s of waiting; one network stall (30 s timeout x retries) pushed it past 1800 s and a timeout kill cannot be caught by the
 * chain's fail-soft. `timeout` is now 1500 s: strictly below Horizon's supervisor timeout (1800 s), itself below the queue's retry_after
 * (1830 s), so the worker timeout — which calls failed() and raises one alert — always fires first, never the supervisor, never a re-reserve.
 *
 * `tries = 1`: a retry is just the next scheduled run (or `hm:sync --entity=catalog`), which resumes from the checkpoint.
 */
final class CatalogSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    public int $uniqueFor = 2100;

    public function __construct(public readonly bool $full = false)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'catalog';
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [app(SkipWhenWooIsNotConfigured::class)];
    }

    public function handle(CatalogSyncService $catalog): void
    {
        $catalog->syncAll($this->full);
    }

    /** A thrown error AND a worker timeout kill both end here: one CatalogSyncFailed alert, the checkpoint stays for the next run. */
    public function failed(Throwable $exception): void
    {
        app(CatalogSyncCheckpoint::class)->recordFailure($exception);
    }
}
