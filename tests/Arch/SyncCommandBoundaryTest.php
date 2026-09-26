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

/*
| The list below grows one file per sprint that adds its own top-level command (same pattern as
| CustomerListBoundaryTest's route count): P4-07 added MetricsRecompute (`metrics:recompute`, PRD
| §11/§26's nightly chain step 6) alongside P2-10's hm:sync and P2-11's hm:reconcile.
*/
it('has hm:sync as its own command file, next to P2-11\'s hm:reconcile and P4-07\'s metrics:recompute, nothing else under app/Console', function () {
    $names = array_map(fn (string $f) => Scanner::relative($f), Scanner::phpFiles(['app/Console']));

    expect($names)->toBe([
        'app/Console/Commands/MetricsRecompute.php',
        'app/Console/Commands/ReconcileCommand.php',
        'app/Console/Commands/SyncCommand.php',
    ])
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
        ->and(substr_count($code, 'SyncEntityJob::dispatch('))->toBe(1)
        ->and(substr_count($code, 'CatalogSyncJob::dispatch('))->toBe(1);
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

it('registers the hm:sync poll in routes/console.php, with no overlap or server flags (P2-11/P6 add more entries beside it)', function () {
    $console = Scanner::phpCode(Scanner::root().'/routes/console.php');

    expect(substr_count($console, 'Schedule::command(\'hm:sync\''))->toBe(2)
        ->and($console)->toContain("Schedule::command('hm:sync', ['--entity' => 'orders'])")
        ->and($console)->toContain('->everyFifteenMinutes()')
        ->and($console)->toContain("Schedule::command('hm:sync', ['--entity' => 'catalog'])")
        ->and($console)->toContain('->dailyAt(')
        ->and($console)->toContain("->timezone('Asia/Tehran')")
        ->and($console)->not->toMatch('/withoutOverlapping|onOneServer|runInBackground|everyMinute\(/');
});

it('adds no health check job in P2-10 or P2-11 (reconciliation itself arrived with P2-11)', function () {
    $names = array_map(fn (string $f) => basename($f), Scanner::phpFiles(['app']));

    expect(array_filter($names, fn (string $n) => preg_match('/HealthCheck/i', $n) === 1))->toBe([]);
});
