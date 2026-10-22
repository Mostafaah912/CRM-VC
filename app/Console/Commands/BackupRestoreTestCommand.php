<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Services\Backup\BackupFailedException;
use App\Modules\Core\Services\Backup\RestoreTestService;
use Illuminate\Console\Command;

/** P8-02: restore the newest backup into the scratch DB `hm_restore_test`, compare it with the live DB, drop it, report the time (RTO). */
final class BackupRestoreTestCommand extends Command
{
    protected $signature = 'backup:restore-test {--key= : Path to the PRIVATE key file (age identity). Given at run time; never stored.}';

    protected $description = 'Download the latest backup, verify its checksum, decrypt and restore it into hm_restore_test, validate, and drop it';

    public function handle(RestoreTestService $restore): int
    {
        $key = (string) $this->option('key');

        if ($key === '') {
            $this->error('--key=/path/to/private-key is required.');

            return self::FAILURE;
        }

        try {
            $result = $restore->run($key);
        } catch (BackupFailedException $e) {
            $this->error('Restore test FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['table', 'restored', 'live at backup time', 'restored max', 'live max at backup time', 'ok'],
            array_map(fn (array $t): array => [$t['table'], $t['restored'], $t['live_at_backup'], $t['restored_max'] ?? '-', $t['live_max_at_backup'] ?? '-', $t['ok'] ? 'yes' : 'NO'], $result->tables),
        );
        $this->info("Restore test OK: {$result->file} restored and validated in {$result->seconds}s (RTO measurement; target 4h). Scratch database dropped.");

        return self::SUCCESS;
    }
}
