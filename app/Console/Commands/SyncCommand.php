<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Jobs\SyncEntityJob;
use Illuminate\Console\Command;

/**
 * `hm:sync` (P2-10): queue ONE sync run for an entity and exit — it neither waits for the run nor decides anything about
 * it (where it starts, how far it goes and what is stored are SyncService's). `--full` asks for a full re-sync; without
 * it, the incremental poll. It prints one line and nothing else: no position, no payload, no secret.
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

        $mode = $this->option('full') ? SyncMode::Full : SyncMode::Incremental;

        SyncEntityJob::dispatch($entity, $mode);

        $this->line("Dispatched sync for {$entity->value} ({$mode->value})");

        return self::SUCCESS;
    }
}
