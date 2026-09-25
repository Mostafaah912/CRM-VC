<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Starts a run for one entity (PRD §22): incremental (the scheduled poll, webhook deliveries) or full (`hm:sync --full`).
 * No logic: it asks SyncService to open the run, which queues page 1. Unique per entity so the scheduler cannot pile up
 * identical start requests; whether a run is already going is SyncService's call.
 */
final class SyncEntityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Transient Woo errors are retried inside HttpWooClient; a failed run is simply started again from the same cursor. */
    public int $tries = 1;

    /** Opening a run is a few queries. uniqueFor stays above the timeout (PRD §22) and the timeout below the queue's retry_after. */
    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly SyncEntity $entity,
        public readonly SyncMode $mode = SyncMode::Incremental,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->entity->value;
    }

    public function handle(SyncService $sync): void
    {
        $sync->run($this->entity, $this->mode);
    }
}
