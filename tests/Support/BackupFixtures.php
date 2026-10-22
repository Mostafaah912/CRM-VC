<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** P8-02 test helpers: a real age key pair, committed rows pg_dump can see, and cleanup of what a committed test leaves behind. */
final class BackupFixtures
{
    public const SENTINEL = 'ZZ-SENTINEL-PLAINTEXT-7431';

    public static function ageBinary(): ?string
    {
        $configured = getenv('BACKUP_AGE_BINARY') ?: null;

        if ($configured !== null && is_executable($configured)) {
            return $configured;
        }

        $which = new Process(['which', 'age']);
        $which->run();

        return $which->isSuccessful() ? trim($which->getOutput()) : null;
    }

    /** @return array{public: string, keyFile: string} a fresh key pair; the private key file is 0600 in the test's temp dir */
    public static function keyPair(?string $binary = null): array
    {
        $keygen = dirname((string) ($binary ?? self::ageBinary())).'/age-keygen';
        $keygen = is_executable($keygen) ? $keygen : 'age-keygen';
        $file = sys_get_temp_dir().'/hm-test-key-'.bin2hex(random_bytes(6)).'.txt';
        $p = new Process([$keygen, '-o', $file]);
        $p->mustRun();
        preg_match('/(age1[0-9a-z]{58})/', $p->getErrorOutput().$p->getOutput(), $m);

        return ['public' => $m[1], 'keyFile' => $file];
    }

    public static function useFakeDisk(string $recipient): void
    {
        self::useDisk();
        config([
            'backup.enabled' => true,
            'backup.encryption' => 'age',
            'backup.age.binary' => self::ageBinary(),
            'backup.age.recipient' => $recipient,
            'logging.default' => 'null',
        ]);
    }

    /** A real local disk under storage/framework/testing, wiped clean (Storage::fake() does not register the disk in config, which BackupSupport reads). */
    public static function useDisk(): void
    {
        $root = storage_path('framework/testing/disks/backup_test');
        File::deleteDirectory($root);
        config([
            'filesystems.disks.backup_test' => ['driver' => 'local', 'root' => $root, 'throw' => true],
            'backup.disk' => 'backup_test',
        ]);
        Storage::forgetDisk('backup_test');
    }

    /** Rows the dump must carry; committed so the separate pg_dump process can see them. */
    public static function seedAndCommit(): void
    {
        User::factory()->count(3)->create();
        Customer::factory()->count(5)->create(['first_name' => self::SENTINEL]);
        Segment::factory()->count(2)->create();

        DB::commit();
    }

    public static function cleanup(): void
    {
        DB::unprepared('TRUNCATE users, customers, segments, audit_logs, settings, roles, permissions RESTART IDENTITY CASCADE');
    }

    /** @return list<string> */
    public static function tempEntries(): array
    {
        $dir = storage_path('app/backup-tmp');

        return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
    }

    public static function scratchDatabaseExists(): bool
    {
        return DB::connection()->table('pg_database')->where('datname', 'hm_restore_test')->exists();
    }
}
