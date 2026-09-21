<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P3-07 boundary: GET /products (routes/internal.php; auth + catalog.view) -> Http\Catalog\ProductListController (its FormRequest,
| ONE service, ONE Inertia response) -> Catalog\ProductListService (read-only) -> Catalog\Support\ProductListRow. The service reads
| `order_items` and `orders` with the query builder — the documented data dependency (ARCHITECTURE.md, P3-03's "Data dependency در
| برابر Module dependency", used here in the same direction as P3-05's CustomerProductsService) — and never `use`s a class of the
| Orders module. Its one grouped SUM/COUNT/MAX is the sole Rule-7 exception outside Migrations/Metrics/Analytics (also in
| ArchitectureTest), and every raw fragment in it is a static string literal — nothing built from a filter or request input.
*/

function cbFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

const CB_CONTROLLER = 'app/Http/Controllers/Catalog/ProductListController.php';

const CB_REQUEST = 'app/Http/Requests/Catalog/ProductListRequest.php';

const CB_SERVICE = 'app/Modules/Catalog/Services/ProductListService.php';

/** @return list<string> */
function cbSupport(): array
{
    return array_map(cbFile(...), [
        'app/Modules/Catalog/Support/ProductListFilters.php',
        'app/Modules/Catalog/Support/ProductListRow.php',
    ]);
}

it('has every file of the product list page', function () {
    $files = [
        cbFile(CB_CONTROLLER), cbFile(CB_REQUEST), cbFile(CB_SERVICE), ...cbSupport(),
        cbFile('resources/js/pages/products/index.tsx'),
        cbFile('resources/js/types/products.ts'),
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

// ================================================================== controller

it('keeps the controller free of queries, models, config and control flow — one service, one Inertia response', function () {
    $controller = cbFile(CB_CONTROLLER);
    $code = Scanner::phpCode($controller);

    expect(Scanner::violations([$controller], [
        '/\bDB::/',
        '/::(query|where|find|findOrFail|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
        '/->(where|update|delete|save|insert|upsert|orderBy|latest|select|create)\s*\(/',
        '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
        '/\b(Log|Queue|Cache|Redis|Http|Auth)::/',
        '/\bconfig\s*\(|\benv\s*\(|\bauth\s*\(/',
        '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(|\btry\b|\bcatch\b/',
    ]))->toBe([])
        ->and(substr_count($code, 'Inertia::render('))->toBe(1)
        ->and($code)->toContain("Inertia::render('products/index'");
    preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
    expect($services[0])->toHaveCount(1);
});

// ================================================================== route

it('registers the list as a GET behind auth and catalog.view, named products.index', function () {
    $routes = Scanner::phpCode(cbFile('routes/internal.php'));

    expect($routes)->toContain("Route::middleware(['auth', 'permission:catalog,view'])")
        ->and($routes)->toMatch("/Route::get\('products', ProductListController::class\)->name\('products\.index'\)/");
});

// ================================================================== the module boundary

it('never `use`s a class of the Orders module — not even the exception CLAUDE.md grants Customers', function () {
    expect(Scanner::violations([cbFile(CB_SERVICE), ...cbSupport(), cbFile(CB_CONTROLLER)], [
        '/App\\\\Modules\\\\Orders\\\\/',
        '/App\\\\Modules\\\\(Customers|Metrics|Sync|Segments|Analytics|Ai)\\\\/',
    ]))->toBe([]);
});

it('never writes, dispatches, logs or reaches Woo — read-only, one query', function () {
    expect(Scanner::violations([cbFile(CB_SERVICE), ...cbSupport()], [
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate|flush|push)\s*\(/',
        '/::(create|updateOrCreate|firstOrCreate|insert|upsert|truncate|dispatch)\s*\(/',
        '/\bDB::(statement|update|insert|delete|transaction)\b/',
        '/\b(Log|Queue|Cache|Redis|Http)::|\blogger\s*\(|\bWooClient\b/',
    ]))->toBe([]);
});

it('names only products, order_items, orders and product_variations — never a table that holds customer, note or identity data', function () {
    $tables = [];
    preg_match_all('/(?:DB::table|->join|->leftJoin(?:Sub)?)\(\s*\'?(\w+)/', Scanner::phpCode(cbFile(CB_SERVICE)), $named);
    array_push($tables, ...$named[1]);

    expect(array_values(array_unique($tables)))->toEqualCanonicalizing(['products', 'order_items', 'orders', 'product_variations'])
        ->and(Scanner::violations([cbFile(CB_SERVICE)], ['/customers|customer_notes|customer_events|phone_reveal_logs|identity_conflicts|audit_logs/i']))->toBe([]);
});

// ================================================================== the Rule 7 exception: aggregate SQL, static only

it('confines the aggregate query to ProductListService, and never builds a raw fragment from filter or request input', function () {
    // No other Catalog file uses selectRaw/DB::raw/orderByRaw — the exception is this one file only.
    $otherCatalogFiles = array_filter(
        Scanner::phpFiles(['app/Modules/Catalog']),
        fn (string $file) => $file !== cbFile(CB_SERVICE),
    );

    expect(Scanner::violations(array_values($otherCatalogFiles), [
        '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw)\s*\(/',
        '/\bDB::(raw|statement|select|unprepared)\b/',
    ]))->toBe([]);

    $service = Scanner::phpCode(cbFile(CB_SERVICE));
    preg_match_all("/(?:selectRaw|orderByRaw|whereRaw)\(\s*'([^']*)'/", $service, $fragments);

    expect($fragments[1])->not->toBeEmpty();

    foreach ($fragments[1] as $fragment) {
        // A static literal never contains PHP interpolation syntax or a concatenation operator pulling in a variable.
        expect($fragment)->not->toContain('$')
            ->and($fragment)->not->toContain('{');
    }

    expect($service)->not->toMatch('/(selectRaw|orderByRaw|whereRaw)\([^)]*\.\s*\$/')
        ->and($service)->not->toMatch('/(selectRaw|orderByRaw|whereRaw)\([^)]*\$\w+->/');
});

it('is a documented Rule 7 exception in ARCHITECTURE.md, not a silent one', function () {
    $architecture = (string) file_get_contents(cbFile('ARCHITECTURE.md'));

    expect($architecture)->toContain('استثنای Rule 7')
        ->and($architecture)->toContain('ProductListService');
});

// ================================================================== realized-only, money, dates

it('sums only realized orders, never a hardcoded status string', function () {
    $service = Scanner::phpCode(cbFile(CB_SERVICE));

    expect($service)->toContain("->where('orders.is_realized', true)")
        ->and($service)->not->toMatch('/[\'"](completed|processing|pending|cancelled|refunded|failed|on-hold)[\'"]/');
});

it('leaves money exactly as stored: int Toman, nothing divided, rounded or floated', function () {
    expect(Scanner::violations([...cbSupport(), cbFile(CB_SERVICE)], [
        '/\(float\)|\(double\)|floatval|\bround\s*\(|\bfloor\s*\(|\bceil\s*\(|\bintdiv\s*\(|number_format|\/\s*10\b/',
    ]))->toBe([])
        ->and(Scanner::phpCode(cbFile('app/Modules/Catalog/Support/ProductListRow.php')))->toContain("'total_revenue' => (int) (\$r['total_revenue'] ?? 0)");
});

it('gives a product with no sale zero counts, never null — and a null last_sold_at, never a fabricated one', function () {
    $row = Scanner::phpCode(cbFile('app/Modules/Catalog/Support/ProductListRow.php'));

    expect($row)->toContain("'total_qty_sold' => (int) (\$r['total_qty_sold'] ?? 0)")
        ->and($row)->toContain("'total_revenue' => (int) (\$r['total_revenue'] ?? 0)")
        ->and($row)->toContain("'order_count' => (int) (\$r['order_count'] ?? 0)")
        ->and($row)->toContain('$lastSoldAt === null ? null :');
});

it('sends every date as Jalali (through TehranDateTime) and ISO (UTC) — never the raw stored value', function () {
    $row = cbFile('app/Modules/Catalog/Support/ProductListRow.php');

    expect(Scanner::violations([$row], ['/->format\s*\(\s*[\'"](?!Y-m-d)/', '/\bdate\s*\(|->toDateTimeString|->toDateString|->toIso8601String\b/']))->toBe([])
        ->and(Scanner::phpCode($row))->toContain('TehranDateTime::format(');
});

it('validates status against Catalog\'s own ProductStatus enum — this is not Woo\'s order-status string', function () {
    $request = Scanner::phpCode(cbFile(CB_REQUEST));

    expect($request)->toContain('Rule::enum(ProductStatus::class)');
});

// ================================================================== the required security constraint

it('never puts a customer\'s name or phone in error_message or a log line', function () {
    expect(Scanner::violations([cbFile(CB_SERVICE), ...cbSupport(), cbFile(CB_CONTROLLER)], [
        '/error_message/i',
        '/\b(Log|logger)::|->(info|warning|error|debug|notice)\s*\(/',
        '/display_name|phone_normalized|phone_masked/i',
    ]))->toBe([]);
});

// ================================================================== the React side

it('writes the page in strict TypeScript, with no fetch of its own and no raw HTML', function () {
    $source = (string) file_get_contents(cbFile('resources/js/pages/products/index.tsx'));

    expect($source)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/')
        ->and($source)->not->toContain('dangerouslySetInnerHTML')
        ->and($source)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b/')
        ->and($source)->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/')
        ->and($source)->not->toContain('ریال')
        ->and($source)->toMatch('/export default function ProductsIndex\(/');
});

it('shows the sidebar link only to a holder of catalog.view', function () {
    $sidebar = (string) file_get_contents(cbFile('resources/js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("can('catalog', 'view')");
});
