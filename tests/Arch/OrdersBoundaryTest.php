<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P3-06 boundary: GET /orders and GET /orders/{order} (routes/internal.php; auth + orders.view) -> Http\Orders\*Controller (its
| FormRequest, ONE service, ONE Inertia response) -> Orders\OrderListService / OrderShowService (read-only) -> Orders\Support DTOs.
| Both services read the `customers` table with the query builder — the documented data dependency (ARCHITECTURE.md, P3-03's
| "Data dependency در برابر Module dependency"), now used in reverse (Orders reading a Customers table) — and never `use` a class
| of the Customers module, not even the one Model CLAUDE.md §1 allows everywhere else. A customer's display name never appears
| in an error_message or a log line.
*/

function obFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

const OB_LIST_SERVICE = 'app/Modules/Orders/Services/OrderListService.php';

const OB_SHOW_SERVICE = 'app/Modules/Orders/Services/OrderShowService.php';

/** @return list<string> */
function obControllers(): array
{
    return array_map(obFile(...), [
        'app/Http/Controllers/Orders/OrderListController.php',
        'app/Http/Controllers/Orders/OrderShowController.php',
    ]);
}

/** @return list<string> */
function obServices(): array
{
    return [obFile(OB_LIST_SERVICE), obFile(OB_SHOW_SERVICE)];
}

/** @return list<string> */
function obSupport(): array
{
    return array_map(obFile(...), [
        'app/Modules/Orders/Support/OrderListFilters.php',
        'app/Modules/Orders/Support/OrderListRow.php',
        'app/Modules/Orders/Support/OrderShowData.php',
    ]);
}

it('has every file of the order list and detail pages', function () {
    $files = [
        ...obControllers(), ...obServices(), ...obSupport(),
        obFile('app/Http/Requests/Orders/OrderListRequest.php'),
        obFile('app/Http/Requests/Orders/OrderShowRequest.php'),
        obFile('resources/js/pages/orders/index.tsx'),
        obFile('resources/js/pages/orders/show.tsx'),
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

// ================================================================== controllers

it('keeps every controller free of queries, models, config and control flow — one service, one Inertia response', function () {
    foreach (obControllers() as $controller) {
        $code = Scanner::phpCode($controller);

        expect(Scanner::violations([$controller], [
            '/\bDB::/',
            '/::(query|where|find|findOrFail|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
            '/->(where|update|delete|save|insert|upsert|orderBy|latest|select|create)\s*\(/',
            '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
            '/\b(Log|Queue|Cache|Redis|Http|Auth)::/',
            '/\bconfig\s*\(|\benv\s*\(|\bauth\s*\(/',
            '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(|\btry\b|\bcatch\b/',
            '/PhoneMask|PhoneNormalizer|phone_normalized/i',
        ]))->toBe([], basename($controller))
            ->and(substr_count($code, 'Inertia::render('))->toBe(1, basename($controller));
        preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
        expect($services[0])->toHaveCount(1, basename($controller));
    }
});

// ================================================================== routes

it('registers the list and the detail page as GETs behind auth and orders.view, numeric ids only', function () {
    $routes = Scanner::phpCode(obFile('routes/internal.php'));

    expect($routes)->toContain("Route::middleware(['auth', 'permission:orders,view'])")
        ->and($routes)->toMatch("/Route::get\('orders', OrderListController::class\)->name\('orders\.index'\)/")
        ->and($routes)->toMatch("/Route::get\('orders\/\{order\}', OrderShowController::class\)->whereNumber\('order'\)->name\('orders\.show'\)/");
});

// ================================================================== the module boundary: no Customers class, not even the exception

it('never `use`s ANY class of the Customers module — not even Customer, the one Model CLAUDE.md allows everywhere else', function () {
    expect(Scanner::violations([...obServices(), ...obSupport(), ...obControllers()], [
        '/App\\\\Modules\\\\Customers\\\\/',
        '/App\\\\Modules\\\\(Metrics|Catalog|Sync|Segments|Analytics|Ai)\\\\/',
    ]))->toBe([]);
});

it('reads only: no write, no dispatch, no log, no raw SQL, no Woo client', function () {
    expect(Scanner::violations(obServices(), [
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate|flush|push)\s*\(/',
        '/::(create|updateOrCreate|firstOrCreate|insert|upsert|truncate|dispatch)\s*\(/',
        '/\bDB::(statement|unprepared|update|insert|delete|transaction|select|raw)/',
        '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw|orWhereRaw)\s*\(|new\s+Expression/',
        '/\b(Log|Queue|Cache|Redis|Http)::|\blogger\s*\(|\bWooClient\b/',
    ]))->toBe([]);
});

it('names only orders, order_items and customers — never a table that holds audit, note or identity data', function () {
    $tables = [];

    foreach (obServices() as $file) {
        preg_match_all('/(?:DB::table|->join|->leftJoin)\(\s*\'(\w+)(?: as \w+)?\'/', Scanner::phpCode($file), $named);
        array_push($tables, ...$named[1]);
    }

    expect(array_values(array_unique($tables)))->toEqualCanonicalizing(['orders', 'order_items', 'customers'])
        ->and(Scanner::violations(obServices(), ['/phone_reveal_logs|customer_identities|customer_addresses|customer_notes|customer_events|audit_logs|identity_conflicts/']))->toBe([]);
});

// ================================================================== the required constraint: never a customer's name in error_message or a log

it('never puts a customer\'s display_name in error_message or a log line — the security constraint the task fixed', function () {
    expect(Scanner::violations([...obServices(), ...obSupport(), ...obControllers()], [
        '/error_message/i',
        '/\b(Log|logger)::|->(info|warning|error|debug|notice)\s*\(/',
    ]))->toBe([]);
});

it('throws a plain ModelNotFoundException for a missing order — no message built from customer or phone data', function () {
    $service = Scanner::phpCode(obFile(OB_SHOW_SERVICE));

    expect($service)->toContain('ModelNotFoundException')
        ->and($service)->not->toMatch('/new\s+\\\\?(RuntimeException|Exception|InvalidArgumentException)\(.*\$(customer|order)(?!Id)/i');
});

// ================================================================== money, dates, phone

it('leaves money exactly as stored: int Toman, nothing divided, rounded or floated', function () {
    expect(Scanner::violations(obSupport(), [
        '/\(float\)|\(double\)|floatval|\bround\s*\(|\bfloor\s*\(|\bceil\s*\(|\bintdiv\s*\(|number_format|\/\s*10\b/',
    ]))->toBe([])
        ->and(Scanner::phpCode(obFile('app/Modules/Orders/Support/OrderListRow.php')))->toContain("'total' => (int) \$r['total']")
        ->and(Scanner::phpCode(obFile('app/Modules/Orders/Support/OrderShowData.php')))->toContain("'total' => (int) \$o['total']");
});

it('sends every date as Jalali (through TehranDateTime) and ISO (UTC) — never the raw stored value', function () {
    expect(Scanner::violations(obSupport(), ['/->format\s*\(\s*[\'"](?!Y-m-d)/', '/\bdate\s*\(|->toDateTimeString|->toDateString|->toIso8601String\b/']))->toBe([])
        ->and(Scanner::phpCode(obFile('app/Modules/Orders/Support/OrderListRow.php')))->toContain('TehranDateTime::format(')
        ->and(Scanner::phpCode(obFile('app/Modules/Orders/Support/OrderShowData.php')))->toContain('TehranDateTime::format(');
});

it('masks the phone through PhoneMask in the DTO — no inline masking, no normalized phone in the output', function () {
    $data = Scanner::phpCode(obFile('app/Modules/Orders/Support/OrderShowData.php'));

    expect($data)->toContain('PhoneMask::mask(')
        ->and(Scanner::violations([obFile('app/Modules/Orders/Support/OrderShowData.php')], ['/\b(str_repeat|substr|mb_substr|preg_replace|str_pad)\s*\(/']))->toBe([]);
});

it('validates status against what is actually stored, never a hardcoded list — CLAUDE.md forbids an order-status enum', function () {
    $request = Scanner::phpCode(obFile('app/Http/Requests/Orders/OrderListRequest.php'));

    expect($request)->toContain('statusOptions()')
        ->and(Scanner::violations([obFile('app/Http/Requests/Orders/OrderListRequest.php')], ['/[\'"](completed|processing|pending|cancelled|refunded|failed|on-hold)[\'"]/']))->toBe([]);
});

// ================================================================== the React side

it('writes the two pages in strict TypeScript, reveals the phone only through PhoneRevealButton on the detail page', function () {
    foreach (['resources/js/pages/orders/index.tsx', 'resources/js/pages/orders/show.tsx'] as $page) {
        $source = (string) file_get_contents(obFile($page));

        expect($source)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/', $page)
            ->and($source)->not->toContain('dangerouslySetInnerHTML')
            ->and($source)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b/')
            ->and($source)->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/')
            ->and($source)->not->toContain('ریال');
    }

    $show = (string) file_get_contents(obFile('resources/js/pages/orders/show.tsx'));

    expect($show)->toContain('@/components/customers/PhoneRevealButton')
        ->and($show)->toMatch('/export default function OrderShow\(/');
});

it('shows the sidebar link only to a holder of orders.view', function () {
    $sidebar = (string) file_get_contents(obFile('resources/js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("can('orders', 'view')");
});
