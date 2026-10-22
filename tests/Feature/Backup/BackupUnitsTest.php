<?php

declare(strict_types=1);

use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\Backup\BackupEncryptorFactory;
use App\Modules\Core\Services\Backup\BackupFailedException;
use App\Modules\Core\Services\Backup\BackupRetention;
use App\Modules\Core\Services\Backup\BackupService;
use App\Modules\Core\Services\Backup\GpgEncryptor;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Support\HealthCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BackupFixtures as Fx;
use Tests\Support\SystemPageFixtures as Sx;

beforeEach(function () {
    config(['logging.default' => 'null']);
    Fx::useDisk();
    app()->instance(WooClient::class, new FakeWooClient([]));
});

function put(string $path): void
{
    Storage::disk('backup_test')->put($path, 'x');
    Storage::disk('backup_test')->put($path.'.sha256', 'h  x');
}

// ------------------------------------------------------------------ retention: exactly 30 days daily / 12 weeks weekly

it('keeps daily backups for exactly 30 days and weekly backups for exactly 12 weeks', function () {
    $now = CarbonImmutable::parse('2026-10-20 02:00', 'UTC');

    foreach ([0, 1, 29, 30] as $age) {
        put('daily/'.$now->subDays($age)->format('Ymd-Hi').'.dump.age'); // 30 days old to the minute: kept
    }
    put('daily/'.$now->subDays(30)->subMinute()->format('Ymd-Hi').'.dump.age'); // one minute older: gone
    put('daily/'.$now->subDays(31)->format('Ymd-Hi').'.dump.age');
    put('weekly/'.$now->subWeeks(11)->format('Ymd-Hi').'.dump.age');
    put('weekly/'.$now->subWeeks(12)->format('Ymd-Hi').'.dump.age'); // exactly 12 weeks: kept
    put('weekly/'.$now->subWeeks(12)->subMinute()->format('Ymd-Hi').'.dump.age');
    put('weekly/'.$now->subWeeks(20)->format('Ymd-Hi').'.dump.age');
    Storage::disk('backup_test')->put('daily/notes.txt', 'not ours'); // foreign files are never touched

    $deleted = app(BackupRetention::class)->prune(Storage::disk('backup_test'), $now);

    $left = collect(Storage::disk('backup_test')->allFiles())->reject(fn ($f) => str_ends_with($f, '.sha256'))->sort()->values()->all();
    expect($deleted)->toBe(4)
        ->and($left)->toBe([
            'daily/'.$now->subDays(30)->format('Ymd-Hi').'.dump.age',
            'daily/'.$now->subDays(29)->format('Ymd-Hi').'.dump.age',
            'daily/'.$now->subDays(1)->format('Ymd-Hi').'.dump.age',
            'daily/'.$now->format('Ymd-Hi').'.dump.age',
            'daily/notes.txt',
            'weekly/'.$now->subWeeks(12)->format('Ymd-Hi').'.dump.age',
            'weekly/'.$now->subWeeks(11)->format('Ymd-Hi').'.dump.age',
        ]);
    expect(Storage::disk('backup_test')->exists('daily/'.$now->subDays(31)->format('Ymd-Hi').'.dump.age.sha256'))->toBeFalse();
});

// ------------------------------------------------------------------ guards and configuration

it('refuses a local disk in production', function () {
    app()['env'] = 'production';
    config(['app.debug' => false, 'backup.disk' => 'backup_local', 'backup.age.recipient' => 'age1'.str_repeat('q', 58)]);

    expect(fn () => app(BackupService::class)->run())->toThrow(BackupFailedException::class, 'not a backup');
});

it('rejects an unknown encryption driver and a malformed recipient', function () {
    config(['backup.encryption' => 'rot13']);
    expect(fn () => app(BackupEncryptorFactory::class)->make())->toThrow(BackupFailedException::class);

    config(['backup.encryption' => 'age', 'backup.age.recipient' => 'not-a-key']);
    expect(fn () => app(BackupEncryptorFactory::class)->make()->encryptEnv())->toThrow(BackupFailedException::class, 'valid age public key');
});

it('builds the gpg fallback from env variables only (nothing interpolated)', function () {
    $gpg = new GpgEncryptor('gpg', 'ops@example.com');

    expect($gpg->extension())->toBe('gpg')
        ->and($gpg->encryptEnv())->toBe(['HM_ENC_BIN' => 'gpg', 'HM_ENC_RECIPIENT' => 'ops@example.com'])
        ->and($gpg->encryptFragment())->toContain('--encrypt')->not->toContain('ops@example.com')
        ->and($gpg->decryptFragment())->toContain('--decrypt')
        ->and(fn () => (new GpgEncryptor('gpg', ''))->encryptEnv())->toThrow(BackupFailedException::class);
});

// ------------------------------------------------------------------ health: stale backup + quarterly reminder

function auditAt(string $action, CarbonImmutable $at): void
{
    $log = app(AuditService::class)->recordSystem($action, 'backup', 0);
    AuditLog::query()->whereKey($log->id)->update(['created_at' => $at]);
}

it('alerts when the newest successful backup is older than 26 hours or missing', function () {
    config(['backup.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));

    expect(app(HealthCheckService::class)->check())->toContain(AlertKind::BackupStale->value);

    Cache::flush();
    auditAt('backup.completed', now()->subHours(25));
    auditAt('backup.restore_test.completed', now()->subDays(5));
    expect(app(HealthCheckService::class)->check())->not->toContain(AlertKind::BackupStale->value);

    Cache::flush();
    auditAt('backup.completed', now()->subHours(27));
    AuditLog::query()->where('action', 'backup.completed')->where('created_at', '>', now()->subHours(26))->delete();
    expect(app(HealthCheckService::class)->check())->toContain(AlertKind::BackupStale->value);
});

it('raises the quarterly restore-test reminder after 90 days without one', function () {
    config(['backup.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));
    auditAt('backup.completed', now()->subHour());

    auditAt('backup.restore_test.completed', now()->subDays(89));
    expect(app(HealthCheckService::class)->check())->not->toContain(AlertKind::RestoreTestOverdue->value);

    Cache::flush();
    AuditLog::query()->where('action', 'backup.restore_test.completed')->delete();
    auditAt('backup.restore_test.completed', now()->subDays(91));
    expect(app(HealthCheckService::class)->check())->toContain(AlertKind::RestoreTestOverdue->value);
});

it('raises no backup alert while backups are switched off', function () {
    config(['backup.enabled' => false]);

    expect(app(HealthCheckService::class)->check())->not->toContain(AlertKind::BackupStale->value);
});

it('shows backup status on the system health page', function () {
    config(['backup.enabled' => true]);
    auditAt('backup.completed', CarbonImmutable::now('UTC')->subHours(30));

    $this->actingAs(Sx::userWith('system.view'))->get('/system/health')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('backup.enabled', true)
            ->where('backup.stale', true)
            ->where('backup.last_backup_at', fn ($v) => is_string($v)));
});

it('schedules backup:run daily at 02:00 Tehran, before the 03:00 chain', function () {
    Artisan::call('schedule:list');
    $out = Artisan::output();

    expect($out)->toContain('backup:run')->and($out)->toContain('hm:nightly-chain');
    expect(collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'backup:run'))->expression)->toBe('0 2 * * *');
});
