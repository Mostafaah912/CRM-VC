<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The nightly chain's last step (PRD §22: "ReconcileJob(2)"): queues ReconcileMonthJob for only the last
 * $count complete months, not the whole history (`ReconciliationService::dispatchRecentMonths()`, already
 * tested there; `hm:reconcile --all` still exists for a full re-check). No logic here (CLAUDE.md §1/§5):
 * a Job resolves a Service and calls it.
 *
 * This step only enqueues further async jobs and returns quickly — `timeout`/`uniqueFor` mirror
 * SyncEntityJob's pair (the closest precedent for "starts work, does not wait for it").
 */
final class ReconcileRecentMonthsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $count = 2)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'reconcile-recent-months';
    }

    public function handle(ReconciliationService $reconciliation): void
    {
        $reconciliation->dispatchRecentMonths($this->count);
    }
}
