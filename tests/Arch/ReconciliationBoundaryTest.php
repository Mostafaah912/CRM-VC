<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-11 boundary: hm:reconcile / ReconcileMonthJob -> Sync\ReconciliationService -> WooClient (orders only) + Orders' public
| services (totals, OrderStatusMapper) + the Sync-owned reconciliation_reports model. Money is integer Toman end to end;
| no status, month or date is spelled in code; only the service writes the table; the published P1-04 migration is untouched.
*/

function reconFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

/** @return list<string> the files that do the arithmetic on money and percentages */
function reconMathFiles(): array
{
    return array_map(reconFile(...), [
        'app/Modules/Sync/Services/ReconciliationService.php',
        'app/Modules/Sync/Support/RevenueVariance.php',
        'app/Modules/Sync/Support/ReconciliationReport.php',
        'app/Modules/Sync/Support/ReconciliationMonths.php',
        'app/Modules/Orders/Services/OrderWindowTotals.php',
    ]);
}

it('has the reconciliation files', function () {
    $files = [
        ...reconMathFiles(),
        'app/Modules/Sync/Jobs/ReconcileMonthJob.php',
        'app/Modules/Sync/Models/ReconciliationReportModel.php',
        'app/Modules/Sync/Enums/ReconciliationStatus.php',
        'app/Modules/Sync/Exceptions/ReconciliationException.php',
        'app/Modules/Sync/Support/GateOneReport.php',
        'app/Console/Commands/ReconcileCommand.php',
    ];

    expect(array_map(fn (string $f) => is_file(reconFile($f)) || is_file($f), $files))->each->toBeTrue();
});

it('does all money and percentage arithmetic in integers: no float, no rounding function, no decimal literal', function () {
    expect(Scanner::violations(reconMathFiles(), [
        '/\(\s*float\s*\)|\bfloatval\b|\bfdiv\b|\bdoubleval\b|\(\s*double\s*\)/',
        '/\b(round|floor|ceil)\s*\(/',
        '/\bnumber_format\b|\bbc(add|sub|mul|div|pow)\b|\bgmp_/',
        '/\b\d+\.\d+\b/',
    ]))->toBe([]);
});

it('reads Woo only through the WooClient, the orders endpoint only — never a reports endpoint, HTTP or credentials', function () {
    $service = reconFile('app/Modules/Sync/Services/ReconciliationService.php');

    expect(Scanner::violations([$service], [
        '/\b(Http|Redis)::/',
        '/woo\.(key|secret|base_url|webhook)|\bWOO_[A-Z]|consumer_(key|secret)/',
        '/[\'"\/]reports\b/i',
        '/[\'"]orders\/|[\'"]products|[\'"]customers/',
    ]))->toBe([])
        ->and(Scanner::phpCode($service))->toContain("'orders'")
        ->and(Scanner::phpCode($service))->toContain('WooClient');
});

it('reaches Orders only through its public services and never through a model', function () {
    expect(Scanner::violations(reconMathFiles(), [
        '/App\\\\Modules\\\\(?!Sync\\\\|Orders\\\\Services\b)/',
        '/App\\\\Modules\\\\Orders\\\\Models\\\\/',
        '/\bDB::/',
    ]))->toBe([]);
});

it('decides "realized" through the shared OrderStatusMapper and never spells a status', function () {
    $service = Scanner::phpCode(reconFile('app/Modules/Sync/Services/ReconciliationService.php'));

    expect($service)->toContain('isRealized(')
        ->and(Scanner::violations(reconMathFiles(), ["/['\"](processing|completed|cancelled|pending|on-hold|failed|refunded|trash|shipped)['\"]/"]))->toBe([]);
});

it('spells no Jalali month or year in code: the first month comes from the epoch config, the calendar from JalaliDate', function () {
    $violations = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        foreach (Scanner::violations([$file], ['/\b(1403|1404|1405)\b/']) as $hit) {
            $violations[] = Scanner::relative($file).': '.$hit;
        }
    }

    expect($violations)->toBe([])
        ->and(Scanner::phpCode(reconFile('app/Modules/Sync/Support/ReconciliationMonths.php')))->toContain('JalaliDate::');
});

it('lets only the reconciliation service write the reports table', function () {
    $allowed = [
        'app/Modules/Sync/Services/ReconciliationService.php',
        'app/Modules/Sync/Models/ReconciliationReportModel.php',
        'app/Modules/Sync/Enums/ReconciliationStatus.php',
    ];
    $violations = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        $relative = Scanner::relative($file);

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        foreach (Scanner::violations([$file], ['/ReconciliationReportModel\b/', '/reconciliation_reports/']) as $hit) {
            $violations[] = "{$relative}: {$hit}";
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the command to the reconciliation service: no database, models, config, Woo client or jobs of its own', function () {
    $command = reconFile('app/Console/Commands/ReconcileCommand.php');

    expect(Scanner::violations([$command], [
        '/\bDB::/',
        '/App\\\\Modules\\\\(?!Sync\\\\(Services|Support|Exceptions)\\\\)/',
        '/App\\\\Modules\\\\Sync\\\\(Models|Jobs)\\\\/',
        '/\bconfig\s*\(/',
        '/\b(Log|Http|Redis|Cache)::/',
        '/\bWooClient\b/',
        '/->(where|update|save|create|delete|insert)\s*\(/',
        '/\$this->(info|comment|warn|table|newLine|alert|question|components|output|getOutput)\b/',
        '/\b(echo|print|dump|dd|var_dump|print_r)\b/',
    ]))->toBe([])
        ->and(Scanner::phpCode($command))->toContain('hm:reconcile {--month=')
        ->and(Scanner::phpCode($command))->toContain('{--all');
});

it('schedules the nightly reconciliation in routes/console.php, daily at 02:00 Asia/Tehran, with no overlap flags', function () {
    $console = Scanner::phpCode(reconFile('routes/console.php'));

    expect($console)->toContain("Schedule::command('hm:reconcile --all')")
        ->and($console)->not->toContain("['--all' => true]")
        ->and($console)->toContain("->dailyAt('02:00')")
        ->and($console)->toContain("->timezone('Asia/Tehran')")
        ->and(substr_count($console, 'Schedule::'))->toBe(2)
        ->and($console)->not->toMatch('/withoutOverlapping|onOneServer|runInBackground/');
});

it('changes the reports table only by a NEW migration: the published P1-04 one is untouched', function () {
    $published = (string) file_get_contents(reconFile('database/migrations/2026_09_18_165744_create_reconciliation_reports_table.php'));
    $additions = array_values(array_filter(scandir(reconFile('database/migrations')), fn (string $f) => str_ends_with($f, '_add_reconciliation_month_and_status_to_reconciliation_reports_table.php')));

    expect($published)->not->toContain('jalali_month')
        ->and($published)->toContain("\$table->integer('woo_orders');")
        ->and($additions)->toHaveCount(1);
});

/*
| This scans app/Modules/Sync — the module P2-11 actually touched — not the whole app tree: Sprint 4
| legitimately added RfmCalculator etc. under app/Modules/Metrics afterwards (P4-01/02/03), and a
| repo-wide scan would flag that real, separately-scoped work as scope creep from a Sprint 2 commit.
| The intent this guards is still exactly P2-11's: no health check, no UI page and no metrics code
| smuggled into Sync's reconciliation feature itself.
*/
it('adds no health check, no UI page and no metrics code in P2-11', function () {
    $names = array_map(fn (string $f) => basename($f), Scanner::phpFiles(['app/Modules/Sync']));

    expect(array_filter($names, fn (string $n) => preg_match('/HealthCheck|Metrics(Engine|Service)|RfmCalculator/i', $n) === 1))->toBe([])
        ->and(Scanner::files(['resources/js/pages'], 'tsx'))->each->not->toMatch('/reconcil/i');
});
