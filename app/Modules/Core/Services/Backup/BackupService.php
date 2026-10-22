<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Services\AlertService;
use App\Modules\Core\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * `backup:run` (P8-02). The WHOLE database is dumped (custom format) — that includes the tables Woo cannot
 * rebuild (segments, notes, users/permissions, audit, ai_insights, settings). pg_dump is piped straight into
 * the public-key encryptor, so no unencrypted dump ever exists as a file; the temp dir only ever holds the
 * ciphertext and is removed in `finally`, success or failure.
 */
final class BackupService
{
    public function __construct(
        private readonly BackupSupport $support,
        private readonly BackupEncryptorFactory $encryptors,
        private readonly BackupRetention $retention,
        private readonly AuditService $audit,
        private readonly AlertService $alerts,
    ) {}

    /** @throws BackupFailedException (after auditing + alerting) */
    public function run(?CarbonImmutable $now = null): BackupResult
    {
        $now ??= CarbonImmutable::now('UTC');
        $startedAt = hrtime(true);
        $dir = null;

        try {
            $disk = $this->support->disk();
            $encryptor = $this->encryptors->make();
            $encryptEnv = $encryptor->encryptEnv(); // validates the recipient BEFORE any dump starts

            $dir = $this->support->makeTempDir();
            $name = $this->support->fileName($now, $encryptor->extension());
            $local = $dir.'/'.$name;

            $this->support->pipeline(
                '"$HM_PG_DUMP" --format=custom --no-owner --no-acl | '.$encryptor->encryptFragment(),
                [
                    ...$this->support->pgEnv((string) config('database.connections.pgsql.database')),
                    ...$encryptEnv,
                    'HM_PG_DUMP' => (string) config('backup.pg_dump_binary'),
                    'HM_OUT' => $local,
                ],
            );

            $bytes = (int) filesize($local);

            if ($bytes === 0) {
                throw new BackupFailedException('The encrypted backup is empty.');
            }

            $sha = hash_file('sha256', $local);
            $checksum = $sha.'  '.$name."\n";

            $weekly = $now->setTimezone('Asia/Tehran')->dayOfWeekIso === (int) config('backup.weekly_iso_weekday');
            $folders = $weekly ? [BackupSupport::DAILY, BackupSupport::WEEKLY] : [BackupSupport::DAILY];

            foreach ($folders as $folder) {
                $this->upload($disk, "{$folder}/{$name}", $local, $bytes);
                $disk->put("{$folder}/{$name}.sha256", $checksum);
            }

            $pruned = $this->retention->prune($disk, $now);
            $seconds = (int) round((hrtime(true) - $startedAt) / 1e9);

            $this->audit->recordSystem('backup.completed', 'backup', 0, after: [
                'file' => "daily/{$name}",
                'bytes' => $bytes,
                'sha256' => $sha,
                'weekly_copy' => $weekly,
                'pruned' => $pruned,
                'seconds' => $seconds,
                'encryption' => $encryptor->extension(),
            ], source: 'backup');

            return new BackupResult("daily/{$name}", $bytes, (string) $sha, $weekly, $pruned, $seconds);
        } catch (Throwable $e) {
            $message = $e instanceof BackupFailedException ? $e->getMessage() : $this->support->scrub($e->getMessage());

            $this->audit->recordSystem('backup.failed', 'backup', 0, after: ['message' => $message], source: 'backup');
            $this->alerts->critical(AlertKind::BackupFailure, 'Backup failed: '.$message, ['at' => $now->toIso8601String()]);

            throw $e instanceof BackupFailedException ? $e : new BackupFailedException($message, previous: $e);
        } finally {
            if ($dir !== null) {
                $this->support->removeDir($dir);
            }
        }
    }

    private function upload(Filesystem $disk, string $path, string $local, int $bytes): void
    {
        $stream = fopen($local, 'rb');

        if ($stream === false) {
            throw new BackupFailedException('Cannot read the encrypted backup for upload.');
        }

        try {
            $ok = $disk->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if ($ok === false || ! $disk->exists($path) || $disk->size($path) !== $bytes) {
            throw new BackupFailedException("Upload of {$path} could not be verified (missing or wrong size).");
        }
    }
}
