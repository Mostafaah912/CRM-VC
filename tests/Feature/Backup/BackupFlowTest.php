<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\Backup\BackupFailedException;
use App\Modules\Core\Services\Backup\BackupService;
use App\Modules\Core\Services\Backup\RestoreTestService;
use App\Modules\Customers\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BackupFixtures as Fx;

/*
| P8-02 — the real thing, end to end, on real PostgreSQL: pg_dump -> age encryption -> (fake) disk -> download,
| checksum, decrypt, pg_restore into hm_restore_test -> compare with the live DB -> drop. Needs the `age` binary
| (set BACKUP_AGE_BINARY or put `age`/`age-keygen` on PATH); CI must install it — without it these tests are skipped
| and the backup path is UNVERIFIED.
*/

beforeEach(function () {
    Fx::ageBinary() !== null || $this->markTestSkipped('age binary not available (set BACKUP_AGE_BINARY)');
    $this->keys = Fx::keyPair();
    Fx::useFakeDisk($this->keys['public']);
    Fx::seedAndCommit();
});

afterEach(function () {
    Fx::cleanup();
    isset($this->keys) && @unlink($this->keys['keyFile']);
});

function disk(): Filesystem
{
    return Storage::disk('backup_test');
}

it('runs end to end: encrypted file + checksum on the disk, then a successful restore test', function () {
    $at = CarbonImmutable::now('UTC');
    $name = $at->format('Ymd-Hi').'.dump.age';
    $result = app(BackupService::class)->run($at);

    expect($result->file)->toBe("daily/{$name}")
        ->and($result->weekly)->toBe($at->setTimezone('Asia/Tehran')->dayOfWeekIso === 6)
        ->and(disk()->exists("daily/{$name}"))->toBeTrue()
        ->and(trim((string) disk()->get("daily/{$name}.sha256")))->toBe($result->sha256."  {$name}")
        ->and(disk()->allFiles())->toHaveCount($result->weekly ? 4 : 2)
        ->and(Fx::tempEntries())->toBe([]);

    // Encrypted: an age file, never a raw pg_dump ("PGDMP"), and the plaintext sentinel appears nowhere in it.
    $bytes = (string) disk()->get($result->file);
    expect(str_starts_with($bytes, 'age-encryption.org/v1'))->toBeTrue()
        ->and(str_contains($bytes, 'PGDMP'))->toBeFalse()
        ->and(str_contains($bytes, Fx::SENTINEL))->toBeFalse();

    $restore = app(RestoreTestService::class)->run($this->keys['keyFile']);

    $tables = collect($restore->tables)->keyBy('table');
    expect($restore->file)->toBe($result->file)
        ->and($tables['customers']['restored'])->toBe(5)
        ->and($tables['users']['restored'])->toBe(3)
        ->and($tables['segments']['restored'])->toBe(2)
        ->and(collect($restore->tables)->every(fn (array $t): bool => $t['ok']))->toBeTrue()
        ->and(Fx::scratchDatabaseExists())->toBeFalse()
        ->and(Fx::tempEntries())->toBe([])
        ->and(AuditLog::query()->where('action', 'backup.completed')->where('actor_type', 'system')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'backup.restore_test.completed')->count())->toBe(1);
});

it('accepts rows created AFTER the backup', function () {
    app(BackupService::class)->run(CarbonImmutable::now('UTC')->subMinutes(2));

    // New live rows after the backup are the only permitted difference.
    Customer::factory()->count(2)->create();
    DB::commit();

    expect(collect(app(RestoreTestService::class)->run($this->keys['keyFile'])->tables)->keyBy('table')['customers']['ok'])->toBeTrue();
});

it('rejects a restore that is missing rows the live database held at backup time', function () {
    app(BackupService::class)->run(CarbonImmutable::now('UTC')->addMinute());

    // A row dated BEFORE the backup time that the dump does not contain (it was inserted afterwards): the restore is incomplete.
    Customer::factory()->create();
    DB::commit();

    expect(fn () => app(RestoreTestService::class)->run($this->keys['keyFile']))
        ->toThrow(BackupFailedException::class, 'customers');
    expect(Fx::scratchDatabaseExists())->toBeFalse();
});

it('adds a weekly copy on Saturdays (Tehran calendar) only', function () {
    app(BackupService::class)->run(CarbonImmutable::parse('2026-10-17 10:00', 'UTC')); // Saturday
    expect(disk()->allFiles())->toContain('weekly/20261017-1000.dump.age', 'weekly/20261017-1000.dump.age.sha256', 'daily/20261017-1000.dump.age');

    app(BackupService::class)->run(CarbonImmutable::parse('2026-10-18 10:00', 'UTC')); // Sunday
    expect(disk()->allFiles())->not->toContain('weekly/20261018-1000.dump.age');
});

it('fails the restore test on a checksum mismatch, alerts, and cleans up', function () {
    app(BackupService::class)->run(CarbonImmutable::now('UTC')->subMinute());
    $file = disk()->files('daily')[0];
    disk()->put(str_ends_with($file, '.sha256') ? $file : $file.'.sha256', str_repeat('0', 64).'  x');

    expect(fn () => app(RestoreTestService::class)->run($this->keys['keyFile']))
        ->toThrow(BackupFailedException::class, 'Checksum mismatch');

    expect(AuditLog::query()->where('action', 'alert.critical')->where('after->kind', 'restore_test_failure')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'backup.restore_test.failed')->count())->toBe(1)
        ->and(Fx::scratchDatabaseExists())->toBeFalse()
        ->and(Fx::tempEntries())->toBe([]);
});

it('fails the restore test with the wrong private key, alerts, and cleans up', function () {
    app(BackupService::class)->run(CarbonImmutable::now('UTC')->subMinute());
    $wrong = Fx::keyPair();

    expect(fn () => app(RestoreTestService::class)->run($wrong['keyFile']))->toThrow(BackupFailedException::class);

    @unlink($wrong['keyFile']);
    expect(AuditLog::query()->where('action', 'alert.critical')->where('after->kind', 'restore_test_failure')->count())->toBe(1)
        ->and(Fx::scratchDatabaseExists())->toBeFalse()
        ->and(Fx::tempEntries())->toBe([]);
});

it('fails the restore test when there is no backup, or the key file is unreadable', function () {
    expect(fn () => app(RestoreTestService::class)->run('/nonexistent/key.txt'))->toThrow(BackupFailedException::class, 'not readable');
    expect(fn () => app(RestoreTestService::class)->run($this->keys['keyFile']))->toThrow(BackupFailedException::class, 'No backup found');
});

it('never writes to the live database: the target guard refuses any name but hm_restore_test', function () {
    app(BackupService::class)->run(CarbonImmutable::now('UTC')->subMinute());
    config(['backup.restore_test.database' => (string) config('database.connections.pgsql.database')]);

    expect(fn () => app(RestoreTestService::class)->run($this->keys['keyFile']))
        ->toThrow(BackupFailedException::class, 'Refusing to restore');

    expect(Customer::query()->count())->toBe(5); // the live database is untouched
});

it('a failed backup raises BackupFailure, leaves nothing on the disk and no temp file', function () {
    config(['backup.pg_dump_binary' => '/usr/bin/false']);

    expect(fn () => app(BackupService::class)->run())->toThrow(BackupFailedException::class);

    expect(disk()->allFiles())->toBe([])
        ->and(Fx::tempEntries())->toBe([])
        ->and(AuditLog::query()->where('action', 'backup.failed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'alert.critical')->where('after->kind', 'backup_failure')->count())->toBe(1);
});

it('refuses a private key configured as the recipient, before any dump starts', function () {
    config(['backup.age.recipient' => 'AGE-SECRET-KEY-1QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ']);

    expect(fn () => app(BackupService::class)->run())->toThrow(BackupFailedException::class, 'PRIVATE key');

    expect(disk()->allFiles())->toBe([])->and(Fx::tempEntries())->toBe([]);
});
