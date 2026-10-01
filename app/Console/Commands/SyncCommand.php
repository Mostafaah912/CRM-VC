<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Jobs\CatalogSyncJob;
use App\Modules\Sync\Jobs\SyncEntityJob;
use Illuminate\Console\Command;

/**
 * `hm:sync` (P2-10): queue ONE sync run for an entity and exit — it neither waits for the run nor decides anything about
 * it (where it starts, how far it goes and what is stored are SyncService's). `--full` asks for a full re-sync; without
 * it, the incremental poll. It prints one line and nothing else: no position, no payload, no secret.
 *
 * `--entity=catalog` (P6 decision, ARCHITECTURE.md) dispatches CatalogSyncJob directly instead — catalog
 * has no incremental/full distinction (it is always a full mirror, CatalogSyncService/P2-05), so `--full`
 * is simply not meaningful for it and is not read on that path.
 */
final class SyncCommand extends Command
{
    protected $signature = 'hm:sync {--entity=orders : What to sync} {--full : Re-read everything from the project start}';

    protected $description = 'Queue a sync run: the incremental poll, or a full re-sync with --full';

    public function handle(): int
    {
        $entity = SyncEntity::tryFrom((string) $this->option('entity'));

        if ($entity === null) {
            $this->error('Unknown entity. Supported: '.implode(', ', array_column(SyncEntity::cases(), 'value')).'.');

            return self::FAILURE;
        }

        if ($entity === SyncEntity::Catalog) {
            CatalogSyncJob::dispatch();
            $message = 'Dispatched sync for catalog';
        } else {
            $mode = $this->option('full') ? SyncMode::Full : SyncMode::Incremental;
            SyncEntityJob::dispatch($entity, $mode);
            $message = "Dispatched sync for {$entity->value} ({$mode->value})";
        }

        $this->line($message);

        return self::SUCCESS;
    }
}
