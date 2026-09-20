<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P3-01 boundary: GET /customers (routes/internal.php, auth + customers.view) -> Http\Customers\CustomerListController (validate,
| ONE service, render) -> Customers\CustomerListService (search, filters, pagination, masking) -> the customers table. The
| controller holds no logic; the query is Query Builder with bindings (no raw SQL); and NOTHING here filters or reads
| customer_metrics — that table is Sprint 4's and still empty.
*/

function clFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

/** @return list<string> */
function clBackendFiles(): array
{
    return array_map(clFile(...), [
        'app/Http/Controllers/Customers/CustomerListController.php',
        'app/Http/Requests/Customers/CustomerListRequest.php',
        'app/Modules/Customers/Services/CustomerListService.php',
        'app/Modules/Customers/Support/CustomerListFilters.php',
        'app/Modules/Customers/Support/CustomerListRow.php',
        'app/Modules/Customers/Support/JalaliDay.php',
        'app/Support/PhoneMask.php',
        'app/Support/Digits.php',
    ]);
}

it('has every file of the customer list', function () {
    $files = [
        ...clBackendFiles(),
        clFile('routes/internal.php'),
        clFile('resources/js/pages/customers/index.tsx'),
        clFile('resources/js/lib/customer-labels.ts'),
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

it('keeps the controller free of queries, models, config and control flow — one service, one Inertia response', function () {
    $controller = clFile('app/Http/Controllers/Customers/CustomerListController.php');
    $code = Scanner::phpCode($controller);

    expect(Scanner::violations([$controller], [
        '/\bDB::/',
        '/::(query|where|find|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
        '/->(where|update|delete|save|insert|upsert|orderBy|latest|select)\s*\(/',
        '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
        '/\b(Log|Queue|Cache|Redis|Http)::/',
        '/\bconfig\s*\(|\benv\s*\(/',
        '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(/',
        '/PermissionService|view_full_phone|PhoneMask|PhoneNormalizer/',
    ]))->toBe([])
        ->and(substr_count($code, 'Inertia::render('))->toBe(1);
    preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
    expect($services[0])->toHaveCount(1);
});

it('registers the list, P3-03\'s customer page and P3-04\'s timeline as the only GET routes in routes/internal.php, behind auth and customers.view, loaded from web.php', function () {
    $routes = Scanner::phpCode(clFile('routes/internal.php'));
    $web = (string) file_get_contents(clFile('routes/web.php'));

    expect(substr_count($routes, 'Route::get('))->toBe(3) // the list, P3-03's customers/{customer} page and P3-04's timeline JSON
        ->and($routes)->not->toMatch('/Route::(put|patch|delete|any|match|resource|apiResource)\b/')
        ->and(substr_count($routes, 'Route::post('))->toBe(1) // P3-02's audited reveal, and nothing else that writes
        ->and($routes)->toContain("'auth'")
        ->and($routes)->toContain("'permission:customers,view'")
        ->and($routes)->toContain("'customers'")
        ->and($web)->toContain("require __DIR__.'/internal.php'");
});

it('builds the query with the Query Builder and bindings only — no raw SQL, no string-built SQL', function () {
    expect(Scanner::violations(clBackendFiles(), [
        '/selectRaw|whereRaw|orWhereRaw|orderByRaw|havingRaw|groupByRaw|DB::raw|new\s+Expression|DB::(statement|unprepared|select)/',
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate)\s*\(/',
    ]))->toBe([]);
});

it('reads only the customers table: nothing about metrics, RFM, churn or CLV is filtered, selected or named', function () {
    expect(Scanner::violations(clBackendFiles(), [
        '/customer_metrics|CustomerMetric|metric_runs|\brfm|r_score|f_score|m_score|churn|clv_|total_orders|total_revenue/i',
    ]))->toBe([])
        ->and(Scanner::violations([clFile('resources/js/pages/customers/index.tsx')], [
            '/rfm|churn|clv|metric/i',
        ]))->toBe([]);
});

it('never reads a phone or a personal column beyond what the row shows', function () {
    expect(Scanner::violations([clFile('app/Modules/Customers/Services/CustomerListService.php'), clFile('app/Modules/Customers/Support/CustomerListRow.php')], [
        '/phone_raw_last|[\'"]email[\'"]|first_name|last_name/',
    ]))->toBe([]);
});

it('masks in the row, unconditionally — the list has no path that can carry a full phone', function () {
    $row = Scanner::phpCode(clFile('app/Modules/Customers/Support/CustomerListRow.php'));
    $service = Scanner::phpCode(clFile('app/Modules/Customers/Services/CustomerListService.php'));

    expect($row)->toContain('PhoneMask::mask(')
        ->and(substr_count($row, '->phone_normalized'))->toBe(1)
        ->and($row)->not->toMatch('/fullPhone|canReveal|view_full_phone/')
        ->and($service)->not->toContain('view_full_phone')
        ->and($service)->not->toContain('PermissionService')
        ->and($service)->toContain('PhoneNormalizer::normalize(')
        ->and($service)->toContain('InvalidPhoneException');

    $others = array_filter(clBackendFiles(), fn (string $f) => ! str_ends_with($f, 'CustomerListRow.php') && ! str_ends_with($f, 'CustomerListService.php') && ! str_ends_with($f, 'PhoneMask.php'));
    expect(Scanner::violations(array_values($others), ['/phone_normalized/']))->toBe([]);
});

it('formats every date through JalaliDate and never builds one from a raw string', function () {
    expect(Scanner::violations([clFile('app/Modules/Customers/Support/CustomerListRow.php')], ['/->format\s*\(/', '/\bdate\s*\(/']))->toBe([])
        ->and(Scanner::phpCode(clFile('app/Modules/Customers/Support/CustomerListRow.php')))->toContain('JalaliDate::format(')
        ->and(Scanner::phpCode(clFile('app/Modules/Customers/Support/JalaliDay.php')))->toContain('JalaliDate::');
});

it('writes the page in strict TypeScript, with the one shared badge, no any, no raw HTML, no console, no fetch of its own', function () {
    $page = (string) file_get_contents(clFile('resources/js/pages/customers/index.tsx'));

    expect($page)->toContain('@/components/status-badge')
        ->and($page)->toContain('@/components/pagination')
        ->and($page)->toMatch('/export default function \w+\(/')
        ->and($page)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/')
        ->and($page)->not->toContain('dangerouslySetInnerHTML')
        ->and($page)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b/')
        ->and($page)->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/');
});

it('shows the sidebar link only to users who can open the page', function () {
    expect((string) file_get_contents(clFile('resources/js/components/app-sidebar.tsx')))->toContain("can('customers', 'view')");
});
