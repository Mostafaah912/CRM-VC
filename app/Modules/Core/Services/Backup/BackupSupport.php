<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/** Shared plumbing for backup:run and backup:restore-test: the disk, the secure temp dir, the shell pipeline, the file names. */
final class BackupSupport
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public function disk(): Filesystem
    {
        $name = (string) config('backup.disk');
        $driver = config("filesystems.disks.{$name}.driver");

        if ($driver === null) {
            throw new BackupFailedException("BACKUP_DISK \"{$name}\" is not a configured filesystem disk.");
        }

        if (app()->isProduction() && $driver === 'local' && ! config('backup.allow_local_disk')) {
            throw new BackupFailedException('Refusing to write backups to a local disk in production: a backup on the same server is not a backup. Use an S3-compatible disk (BACKUP_DISK=backup_s3).');
        }

        return Storage::disk($name);
    }

    /** A fresh 0700 directory under storage/app/backup-tmp. Caller MUST pass it to removeDir() in a finally. */
    public function makeTempDir(): string
    {
        $base = storage_path('app/backup-tmp');

        if (! is_dir($base) && ! mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new BackupFailedException('Cannot create the backup temp directory.');
        }

        $dir = $base.'/'.bin2hex(random_bytes(8));

        if (! mkdir($dir, 0700)) {
            throw new BackupFailedException('Cannot create a private backup temp directory.');
        }

        return $dir;
    }

    public function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Run a bash pipeline whose every variable part arrives through $env (never interpolated into $script).
     *
     * @param  array<string, string>  $env
     * @return string stderr (for diagnostics; scrubbed by the caller via scrub())
     */
    public function pipeline(string $script, array $env): string
    {
        $process = new Process(['bash', '-c', 'set -o pipefail; umask 077; '.$script], null, $env);
        $process->setTimeout((float) config('backup.process_timeout_seconds'));

        try {
            $process->run();
        } catch (Throwable $e) {
            throw new BackupFailedException('Backup process could not run: '.$this->scrub($e->getMessage()), previous: $e);
        }

        if (! $process->isSuccessful()) {
            throw new BackupFailedException('Backup process failed (exit '.$process->getExitCode().'): '.$this->scrub($process->getErrorOutput()));
        }

        return $process->getErrorOutput();
    }

    /**
     * Postgres client environment for a connection config (password via env, never argv).
     *
     * @return array<string, string>
     */
    public function pgEnv(string $database): array
    {
        $c = (array) config('database.connections.pgsql');

        return array_filter([
            'PGHOST' => (string) ($c['host'] ?? ''),
            'PGPORT' => (string) ($c['port'] ?? ''),
            'PGUSER' => (string) ($c['username'] ?? ''),
            'PGPASSWORD' => (string) ($c['password'] ?? ''),
            'PGSSLMODE' => (string) ($c['sslmode'] ?? 'prefer'),
            'PGDATABASE' => $database,
        ], fn (string $v): bool => $v !== '');
    }

    /** Keep diagnostics short and free of anything that looks like a credential. */
    public function scrub(string $text): string
    {
        $text = preg_replace('/(password|secret|token|key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $text) ?? '';
        $text = preg_replace('/AGE-SECRET-KEY-\S+/', '[REDACTED]', $text) ?? '';

        return mb_substr(trim($text), 0, 400);
    }

    public function fileName(CarbonImmutable $at, string $extension): string
    {
        return $at->utc()->format('Ymd-Hi').'.dump.'.$extension;
    }

    /** The UTC minute a backup file was taken, from its name; null if the name is not ours. */
    public function timestampOf(string $path): ?CarbonImmutable
    {
        if (preg_match('/(\d{8})-(\d{4})\.dump\.[a-z]+(\.sha256)?$/', $path, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd-Hi', $m[1].'-'.$m[2], 'UTC');
    }

    /**
     * Encrypted dump files (not checksums) under a prefix, newest first.
     *
     * @return list<string>
     */
    public function listDumps(Filesystem $disk, string $prefix): array
    {
        $files = array_values(array_filter(
            $disk->files($prefix),
            fn (string $f): bool => ! str_ends_with($f, '.sha256') && $this->timestampOf($f) !== null,
        ));
        rsort($files);

        return $files;
    }
}
