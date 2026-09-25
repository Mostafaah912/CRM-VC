<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-12 boundary: GET /system/{health,sync-logs,identity-conflicts} -> System\*Controller (validate, ONE service, Inertia) ->
| Sync\SyncHealthService | Sync\SyncRunLogService | Customers\IdentityConflictService (read-only) -> the module's own tables.
| Read-only end to end; a row carries nothing sensitive because the row classes never select it; the pages are strict TypeScript.
*/

function sysFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

/** @return list<string> */
function sysControllers(): array
{
    return array_map(sysFile(...), [
        'app/Http/Controllers/System/HealthController.php',
        'app/Http/Controllers/System/SyncLogsController.php',
        'app/Http/Controllers/System/IdentityConflictsController.php',
    ]);
}

/** @return list<string> */
function sysSyncFiles(): array
{
    return array_map(sysFile(...), [
        'app/Modules/Sync/Services/SyncHealthService.php',
        'app/Modules/Sync/Services/SyncRunLogService.php',
        'app/Modules/Sync/Support/SyncHealthReport.php',
        'app/Modules/Sync/Support/SyncRunRow.php',
    ]);
}

/** @return list<string> */
function sysReadFiles(): array
{
    return [
        ...sysSyncFiles(),
        sysFile('app/Modules/Customers/Services/IdentityConflictService.php'),
        sysFile('app/Modules/Customers/Support/IdentityConflictRow.php'),
    ];
}

/** @return list<string> */
function sysPages(): array
{
    return array_map(sysFile(...), [
        'resources/js/pages/system/health.tsx',
        'resources/js/pages/system/sync-logs.tsx',
        'resources/js/pages/system/identity-conflicts.tsx',
    ]);
}

it('has every file of the three pages', function () {
    $files = [
        ...sysControllers(), ...sysReadFiles(), ...sysPages(),
        sysFile('app/Http/Requests/System/SyncLogsRequest.php'),
        sysFile('app/Support/TehranDateTime.php'),
        sysFile('routes/system.php'),
        sysFile('resources/js/components/status-badge.tsx'),
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

// ================================================================== controllers

it('keeps each controller free of queries, models, config and control flow — one service, one Inertia response', function () {
    foreach (sysControllers() as $controller) {
        $code = Scanner::phpCode($controller);

        expect(Scanner::violations([$controller], [
            '/\bDB::/',
            '/::(query|where|find|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
            '/->(where|update|delete|save|insert|upsert|orderBy|latest|select)\s*\(/',
            '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
            '/\b(Log|Queue|Cache|Redis|Http)::/',
            '/\bconfig\s*\(|\benv\s*\(/',
            '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(/',
        ]))->toBe([], basename($controller))
            ->and(substr_count($code, 'Inertia::render('))->toBe(1, basename($controller));
        preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
        expect($services[0])->toHaveCount(1, basename($controller));
    }
});

// ================================================================== routes

it('registers only GET routes, each behind auth and its own permission — the three pages and nothing else', function () {
    $routes = Scanner::phpCode(sysFile('routes/system.php'));
    $web = (string) file_get_contents(sysFile('routes/web.php'));

    expect(substr_count($routes, 'Route::get('))->toBe(3)
        ->and($routes)->not->toMatch('/Route::(post|put|patch|delete|any|match|resource|apiResource)\b/')
        ->and($routes)->toContain("'auth'")
        ->and($routes)->toContain("'permission:system,view'")
        ->and($routes)->toContain("'permission:identity,review'")
        ->and($routes)->toContain("'health'")
        ->and($routes)->toContain("'sync-logs'")
        ->and($routes)->toContain("'system/identity-conflicts'")
        ->and($web)->toContain("require __DIR__.'/system.php'");
});

// ================================================================== read-only, nothing sensitive

it('reads only: no write, no dispatch, no Woo client, no raw SQL in any new service or row class', function () {
    expect(Scanner::violations(sysReadFiles(), [
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate|flush|push)\s*\(/',
        '/::(create|updateOrCreate|firstOrCreate|insert|upsert|truncate|dispatch)\s*\(/',
        '/\bDB::(statement|unprepared|raw|update|insert|delete)/',
        '/selectRaw|whereRaw|orderByRaw|havingRaw|DB::raw/',
        '/\bWooClient\b|\bHttp::|\bRedis::/',
        '/\bconfig\s*\(|\benv\s*\(/',
    ]))->toBe([]);
});

it('never touches a cursor, a credential or the queue tables — depth and failures come through the queue API', function () {
    expect(Scanner::violations(sysSyncFiles(), [
        '/cursor/i',
        '/woo\.(key|secret|base_url|webhook)|consumer_(key|secret)|webhook_secret/i',
        '/->(payload|exception)\b|[\'"](payload|exception)[\'"]/',
    ]))->toBe([])
        ->and(Scanner::violations([
            sysFile('app/Modules/Sync/Services/SyncHealthService.php'),
            sysFile('app/Modules/Sync/Services/SyncRunLogService.php'),
        ], ['/[\'"](jobs|failed_jobs)[\'"]/', '/->table\s*\(|\bDB::table/']))->toBe([]);
});

it('never selects a name, a phone, a customer or an internal id for the identity-conflict list', function () {
    expect(Scanner::violations([
        sysFile('app/Modules/Customers/Services/IdentityConflictService.php'),
        sysFile('app/Modules/Customers/Support/IdentityConflictRow.php'),
    ], [
        '/existing_name|incoming_name|customer_id|resolved_by|phone/i',
        '/->(with|load|join|leftJoin|whereHas)\s*\(/',
        '/\bCustomer\b|\bOrder\b/',
    ]))->toBe([]);
});

it('keeps the sync-run row free of ids, modes and cursors, and shows an error only for a failed run', function () {
    $row = Scanner::phpCode(sysFile('app/Modules/Sync/Support/SyncRunRow.php'));
    $service = Scanner::phpCode(sysFile('app/Modules/Sync/Services/SyncRunLogService.php'));

    expect($row)->toContain('SyncStatus::Failed')
        ->and($row)->not->toMatch('/[\'"](id|mode|records_failed)[\'"]/')
        ->and($service)->toContain('->select(SyncRunRow::COLUMNS)')
        ->and($service)->not->toMatch('/[\'"]\*[\'"]/');
});

it('formats every displayed date through TehranDateTime, which goes through JalaliDate — never date() or format()', function () {
    expect(Scanner::violations([
        sysFile('app/Modules/Sync/Support/SyncRunRow.php'),
        sysFile('app/Modules/Sync/Support/SyncHealthReport.php'),
        sysFile('app/Modules/Sync/Services/SyncHealthService.php'),
        sysFile('app/Modules/Customers/Support/IdentityConflictRow.php'),
    ], ['/->format\s*\(/', '/\bdate\s*\(/', '/->toIso8601String|->toDateTimeString|->toDateString/']))->toBe([])
        ->and(Scanner::phpCode(sysFile('app/Support/TehranDateTime.php')))->toContain('JalaliDate::format(');
});

// ================================================================== the React side

it('writes the pages in strict TypeScript: no any, no raw HTML, no console, no fetch of its own', function () {
    foreach ([...sysPages(), sysFile('resources/js/components/status-badge.tsx')] as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/', basename($file))
            ->and($source)->not->toContain('dangerouslySetInnerHTML')
            ->and($source)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b/')
            ->and($source)->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/');
    }

    foreach (sysPages() as $page) {
        expect((string) file_get_contents($page))->toMatch('/export default function \w+\(/');
    }
});

it('shows the links in the sidebar only to users who can open the pages', function () {
    $sidebar = (string) file_get_contents(sysFile('resources/js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("can('system', 'view')")
        ->and($sidebar)->toContain("can('identity', 'review')");
});

it('reuses one StatusBadge for all three pages instead of styling badges by hand', function () {
    foreach (sysPages() as $page) {
        expect((string) file_get_contents($page))->toContain('@/components/status-badge');
    }
});
