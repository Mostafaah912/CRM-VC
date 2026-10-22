<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Services\Backup\BackupFailedException;
use App\Modules\Core\Services\Backup\BackupService;
use Illuminate\Console\Command;

/** P8-02: encrypted off-server database backup. A thin wrapper: all logic is BackupService. */
final class BackupRunCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Dump the whole database, encrypt it with the public key and upload it to the backup disk (daily/, plus weekly/ on Saturdays), then prune';

    public function handle(BackupService $backup): int
    {
        try {
            $result = $backup->run();
        } catch (BackupFailedException $e) {
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup OK: {$result->file} ({$result->bytes} bytes, sha256 {$result->sha256}) in {$result->seconds}s".($result->weekly ? ' [+weekly copy]' : '').", pruned {$result->pruned}.");

        return self::SUCCESS;
    }
}
