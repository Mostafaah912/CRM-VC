<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-10 boundary: `hm:sync` (dispatch ONE SyncEntityJob, print one line) -> SyncEntityJob -> SyncService, which alone
| decides windows, chunks and the cursor. The command holds no logic, reads no config, touches no table and prints
| nothing but its line. The epoch comes from the Jalali calendar via config; the cursor still advances in one place.
*/

function syncCommandFile(): string
{
    return Scanner::root().'/app/Console/Commands/SyncCommand.php';
}

it('adds exactly one console command, hm:sync, and nothing else under app/Console', function () {
    $names = array_map(fn (string $f) => Scanner::relative($f), Scanner::phpFiles(['app/Console']));

    expect($names)->toBe(['app/Console/Commands/SyncCommand.php'])
        ->and(Scanner::phpCode(syncCommandFile()))->toMatch('/hm:sync \{--entity=orders[^}]*\} \{--full[^}]*\}/');
});

it('keeps the command free of logic: no database, models, services, config or cursor — one job dispatch', function () {
    $code = Scanner::phpCode(syncCommandFile());

    expect(Scanner::violations([syncCommandFile()], [
        '/\bDB::/',
        '/App\\\\Modules\\\\(?!Sync\\\\(Enums|Jobs)\\\\)/',
        '/App\\\\Modules\\\\Sync\\\\(Models|Services|Support|Mappers)\\\\/',
        '/\bconfig\s*\(/',
        '/\b(Log|Http|Redis|Cache)::/',
        '/->(where|update|save|create|delete|insert)\s*\(/',
        '/cursor|sync_cursors|sync_jobs|epoch|window/i',
        '/\bfor(each)?\s*\(|\bwhile\s*\(/',
    ]))->toBe([])
        ->and(substr_count($code, 'SyncEntityJob::dispatch('))->toBe(1);
});

it('prints only its one line, plus a static error for an unknown entity — never a variable that could be a secret', function () {
    $code = Scanner::phpCode(syncCommandFile());

    expect(Scanner::violations([syncCommandFile()], [
        '/\$this->(info|comment|warn|table|newLine|alert|question|components|output|getOutput)\b/',
        '/\b(echo|print|dump|dd|var_dump|print_r)\b/',
    ]))->toBe([])
        ->and(substr_count($code, '$this->line('))->toBe(1)
        ->and($code)->toContain('Dispatched sync for {$entity->value} ({$mode->value})')
        ->and(substr_count($code, '$this->error('))->toBe(1);
});

it('validates the entity against the SyncEntity enum and exits 1 when it is unknown', function () {
    $code = Scanner::phpCode(syncCommandFile());

    expect($code)->toContain('SyncEntity::tryFrom(')
        ->and($code)->toContain('self::FAILURE');
});

it('still advances the stored cursor in exactly one place — a full run and a chunk both go through complete()', function () {
    $assignments = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        $isModel = str_starts_with(Scanner::relative($file), 'app/Modules/Sync/Models/');
        $patterns = $isModel ? ['/->cursor_value\s*=(?!=)/'] : ['/->cursor_value\s*=(?!=)/', '/[\'"]cursor_value[\'"]\s*=>/'];

        foreach (Scanner::violations([$file], $patterns) as $hit) {
            $assignments[] = Scanner::relative($file).' '.$hit;
        }
    }

    expect($assignments)->toHaveCount(1)
        ->and($assignments[0])->toStartWith('app/Modules/Sync/Services/SyncService.php');
});

it('derives the epoch from the Jalali calendar in config and never spells a date in code', function () {
    $config = Scanner::phpCode(Scanner::root().'/config/woo.php');
    $service = Scanner::phpCode(Scanner::root().'/app/Modules/Sync/Services/SyncService.php');

    expect($config)->toContain("'sync_epoch' => JalaliDate::toGregorian(1403, 7, 1)")
        ->and($config)->toContain("'sync_max_pages_per_run' => 500")
        ->and(Scanner::violations([Scanner::root().'/config/woo.php', Scanner::root().'/app/Modules/Sync/Services/SyncService.php', syncCommandFile()], ['/\b(19|20)\d\d-\d\d-\d\d\b/']))->toBe([])
        ->and($service)->toContain("config('woo.sync_epoch')")
        ->and($service)->toContain("config('woo.sync_max_pages_per_run'")
        ->and($service)->not->toContain('JalaliDate');
});

it('registers one schedule entry, in routes/console.php, with no overlap or server flags', function () {
    $console = Scanner::phpCode(Scanner::root().'/routes/console.php');

    expect(substr_count($console, 'Schedule::'))->toBe(1)
        ->and($console)->toContain("Schedule::command('hm:sync', ['--entity' => 'orders'])")
        ->and($console)->toContain('->everyFifteenMinutes()')
        ->and($console)->toContain("->timezone('Asia/Tehran')")
        ->and($console)->not->toMatch('/withoutOverlapping|onOneServer|runInBackground|everyMinute\(/');
});

it('adds no reconciliation, no health check job and no new webhook code in P2-10', function () {
    $names = array_map(fn (string $f) => basename($f), Scanner::phpFiles(['app']));

    expect(array_filter($names, fn (string $n) => preg_match('/Reconcil|HealthCheck/i', $n) === 1))->toBe([]);
});
