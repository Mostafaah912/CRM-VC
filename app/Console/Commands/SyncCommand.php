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
 * `--entity=catalog` (P6 decision, ARCHITECTURE.md) dispatches CatalogSyncJob directly instead. Since P8-03g the catalog is incremental too:
 * without `--full` the job reads what changed since its last completed run (or resumes a checkpointed run), with `--full` it is a full
 * rebuild (the weekly schedule). Whether it actually resumes or starts over is the checkpoint's decision, not this command's.
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
            $full = (bool) $this->option('full');
            CatalogSyncJob::dispatch($full);
            $message = 'Dispatched sync for catalog'.($full ? ' (full)' : '');
        } else {
            $mode = $this->option('full') ? SyncMode::Full : SyncMode::Incremental;
            SyncEntityJob::dispatch($entity, $mode);
            $message = "Dispatched sync for {$entity->value} ({$mode->value})";
        }

        $this->line($message);

        return self::SUCCESS;
    }
}
