<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Services\AlertService;
use App\Modules\Core\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * `backup:restore-test` (P8-02): proves the newest backup really restores, and measures the time (RTO).
 *
 * Safety: the ONLY database it ever writes to is `hm_restore_test` (config/backup.php, asserted against the live
 * database name before anything runs). The decrypted dump is piped into pg_restore — never written to disk. The
 * private key is a file path handed in for this one run; it is read by the decryptor only and never stored/logged.
 *
 * Validation: a live database keeps changing after the backup, so the restored copy must contain at least
 * everything the live DB held AT THE BACKUP MINUTE: per key table, restored count >= live rows with created_at <= backup
 * time, and restored max(created_at) >= live max(created_at) among those rows. Rows created after the backup are the
 * only permitted difference.
 */
final class RestoreTestService
{
    private const MAINTENANCE = 'backup_maintenance';

    private const RESTORED = 'backup_restored';

    public function __construct(
        private readonly BackupSupport $support,
        private readonly BackupEncryptorFactory $encryptors,
        private readonly AuditService $audit,
        private readonly AlertService $alerts,
    ) {}

    /** @throws BackupFailedException (after auditing + alerting) */
    public function run(string $keyFile): RestoreTestResult
    {
        $startedAt = hrtime(true);
        $dir = null;
        $created = false;
        $target = (string) config('backup.restore_test.database');

        try {
            $this->assertSafeTarget($target);

            if (! is_file($keyFile) || ! is_readable($keyFile)) {
                throw new BackupFailedException('The private key file given in --key is not readable.');
            }

            $disk = $this->support->disk();
            $encryptor = $this->encryptors->make();
            $dumps = $this->support->listDumps($disk, BackupSupport::DAILY);

            if ($dumps === []) {
                throw new BackupFailedException('No backup found in daily/.');
            }

            $remote = $dumps[0];
            $backupAt = $this->support->timestampOf($remote) ?? throw new BackupFailedException('Cannot read the backup time from its file name.');
            $dir = $this->support->makeTempDir();
            $local = $dir.'/'.basename($remote);

            $this->download($disk, $remote, $local);
            $this->verifyChecksum($disk, $remote, $local);

            $this->maintenance("DROP DATABASE IF EXISTS \"{$target}\" WITH (FORCE)");
            $this->maintenance("CREATE DATABASE \"{$target}\"");
            $created = true;

            $this->support->pipeline(
                $encryptor->decryptFragment().' | "$HM_PG_RESTORE" --no-owner --no-acl --exit-on-error --dbname "$PGDATABASE"',
                [
                    ...$this->support->pgEnv($target),
                    ...$encryptor->decryptEnv($keyFile, $dir),
                    'HM_PG_RESTORE' => (string) config('backup.pg_restore_binary'),
                    'HM_IN' => $local,
                ],
            );

            $tables = $this->validate($target, $backupAt);
            $failed = array_filter($tables, fn (array $t): bool => ! $t['ok']);

            if ($failed !== []) {
                throw new BackupFailedException('Restored data does not match the live database for: '.implode(', ', array_column($failed, 'table')).'.');
            }

            $seconds = (int) round((hrtime(true) - $startedAt) / 1e9);
            $this->audit->recordSystem('backup.restore_test.completed', 'backup', 0, after: [
                'file' => $remote,
                'seconds' => $seconds,
                'tables' => count($tables),
            ], source: 'backup');

            return new RestoreTestResult($remote, $seconds, $tables);
        } catch (Throwable $e) {
            $message = $e instanceof BackupFailedException ? $e->getMessage() : $this->support->scrub($e->getMessage());

            $this->audit->recordSystem('backup.restore_test.failed', 'backup', 0, after: ['message' => $message], source: 'backup');
            $this->alerts->critical(AlertKind::RestoreTestFailure, 'Restore test failed: '.$message);

            throw $e instanceof BackupFailedException ? $e : new BackupFailedException($message, previous: $e);
        } finally {
            if ($created || $target !== '') {
                try {
                    $this->assertSafeTarget($target);
                    $this->maintenance("DROP DATABASE IF EXISTS \"{$target}\" WITH (FORCE)");
                } catch (Throwable) {
                    // Cleanup must never mask the real result; a leftover scratch DB is dropped at the start of the next run.
                }
            }

            DB::purge(self::MAINTENANCE);
            DB::purge(self::RESTORED);

            if ($dir !== null) {
                $this->support->removeDir($dir);
            }
        }
    }

    /** The explicit guard: write access is only ever granted to the fixed scratch database, never the live one. */
    private function assertSafeTarget(string $target): void
    {
        $live = (string) config('database.connections.pgsql.database');

        if ($target !== 'hm_restore_test' || $target === $live || str_contains($live, 'hm_restore_test')) {
            throw new BackupFailedException('Refusing to restore: the target must be the scratch database "hm_restore_test" and never the live database.');
        }
    }

    private function maintenance(string $sql): void
    {
        $this->assertSafeTarget((string) config('backup.restore_test.database'));
        Config::set('database.connections.'.self::MAINTENANCE, [...(array) config('database.connections.pgsql'), 'database' => 'postgres']);
        DB::connection(self::MAINTENANCE)->statement($sql);
    }

    private function download(Filesystem $disk, string $remote, string $local): void
    {
        $in = $disk->readStream($remote);
        $out = fopen($local, 'wb');

        if (! is_resource($in) || $out === false) {
            throw new BackupFailedException('Cannot download the backup.');
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function verifyChecksum(Filesystem $disk, string $remote, string $local): void
    {
        if (! $disk->exists($remote.'.sha256')) {
            throw new BackupFailedException('The checksum file is missing next to the backup.');
        }

        $expected = strtolower(trim(explode(' ', trim((string) $disk->get($remote.'.sha256')))[0]));

        if (! hash_equals($expected, (string) hash_file('sha256', $local))) {
            throw new BackupFailedException('Checksum mismatch: the downloaded backup is corrupt or was tampered with.');
        }
    }

    /** @return list<array{table: string, restored: int, live_at_backup: int, restored_max: string|null, live_max_at_backup: string|null, ok: bool}> */
    private function validate(string $target, CarbonImmutable $backupAt): array
    {
        Config::set('database.connections.'.self::RESTORED, [...(array) config('database.connections.pgsql'), 'database' => $target]);
        $restored = DB::connection(self::RESTORED);
        $live = DB::connection();
        $rows = [];

        foreach ((array) config('backup.restore_test.tables') as $table => $column) {
            if (! Schema::connection(self::RESTORED)->hasTable($table)) {
                $rows[] = ['table' => $table, 'restored' => 0, 'live_at_backup' => 0, 'restored_max' => null, 'live_max_at_backup' => null, 'ok' => false];

                continue;
            }

            $restoredCount = (int) $restored->table($table)->count();

            if ($column === null) {
                $liveCount = (int) $live->table($table)->count();
                $rows[] = ['table' => $table, 'restored' => $restoredCount, 'live_at_backup' => $liveCount, 'restored_max' => null, 'live_max_at_backup' => null, 'ok' => $restoredCount > 0 || $liveCount === 0];

                continue;
            }

            $liveBefore = $live->table($table)->where($column, '<=', $backupAt);
            $liveCount = (int) (clone $liveBefore)->count();
            $liveMax = (clone $liveBefore)->max($column);
            $restoredMax = $restored->table($table)->max($column);

            $ok = $restoredCount >= $liveCount
                && ($liveMax === null || ($restoredMax !== null && CarbonImmutable::parse((string) $restoredMax)->gte(CarbonImmutable::parse((string) $liveMax))));

            $rows[] = [
                'table' => $table,
                'restored' => $restoredCount,
                'live_at_backup' => $liveCount,
                'restored_max' => $restoredMax === null ? null : (string) $restoredMax,
                'live_max_at_backup' => $liveMax === null ? null : (string) $liveMax,
                'ok' => $ok,
            ];
        }

        return $rows;
    }
}
