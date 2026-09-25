<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P4-08 part B boundary: GET /metrics/rfm (routes/internal.php; auth + metrics.view) ->
| Http\Metrics\RfmPageController (no FormRequest — no input at all) -> Metrics\RfmPageService
| (read-only) -> Inertia. Reads only customer_metrics/metric_runs, both owned by Metrics — no other
| module's table or model is ever named. top_champions never carries a name or phone.
*/

function rfmFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

const RFM_CONTROLLER = 'app/Http/Controllers/Metrics/RfmPageController.php';

const RFM_SERVICE = 'app/Modules/Metrics/Services/RfmPageService.php';

it('has every file of the RFM page', function () {
    $files = [
        rfmFile(RFM_CONTROLLER), rfmFile(RFM_SERVICE),
        rfmFile('resources/js/pages/metrics/rfm.tsx'),
        rfmFile('resources/js/types/metrics.ts'),
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

it('registers metrics/rfm as a GET behind auth and metrics,view, named metrics.rfm', function () {
    $routes = Scanner::phpCode(rfmFile('routes/internal.php'));

    expect($routes)->toContain("Route::middleware(['auth', 'permission:metrics,view'])")
        ->and($routes)->toMatch("/Route::get\('metrics\/rfm', RfmPageController::class\)->name\('metrics\.rfm'\)/");
});

it('keeps the controller free of queries, models, config and control flow — one service, one Inertia response', function () {
    $controller = rfmFile(RFM_CONTROLLER);
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
        ->and($code)->toContain("Inertia::render('metrics/rfm'");
    preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
    expect($services[0])->toHaveCount(1);
});

it('never `use`s a class of another module — reads only customer_metrics and metric_runs, both its own', function () {
    $service = rfmFile(RFM_SERVICE);

    expect(Scanner::violations([$service, rfmFile(RFM_CONTROLLER)], [
        '/App\\\\Modules\\\\(Orders|Customers|Catalog|Sync|Segments|Analytics|Ai)\\\\/',
    ]))->toBe([]);

    $tables = [];
    preg_match_all('/DB::table\(\s*\'(\w+)\'/', Scanner::phpCode($service), $named);
    array_push($tables, ...$named[1]);

    expect(array_values(array_unique($tables)))->toEqualCanonicalizing(['customer_metrics', 'metric_runs']);
});

it('never writes, dispatches, logs or reaches Woo — read-only', function () {
    expect(Scanner::violations([rfmFile(RFM_SERVICE)], [
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate|flush|push)\s*\(/',
        '/::(create|updateOrCreate|firstOrCreate|insert|upsert|truncate|dispatch)\s*\(/',
        '/\bDB::(statement|update|insert|delete|transaction)\b/',
        '/\b(Log|Queue|Cache|Redis|Http)::|\blogger\s*\(|\bWooClient\b/',
    ]))->toBe([]);
});

it('never puts a customer\'s name or phone anywhere in the RFM page\'s files', function () {
    expect(Scanner::violations([rfmFile(RFM_SERVICE), rfmFile(RFM_CONTROLLER)], [
        '/display_name|phone_normalized|phone_masked|phone_raw_last|first_name|last_name|[\'"]email[\'"]/i',
    ]))->toBe([]);
});

it('formats the latest run date through TehranDateTime, never a raw string', function () {
    $service = Scanner::phpCode(rfmFile(RFM_SERVICE));

    expect($service)->toContain('TehranDateTime::format(')
        ->and(Scanner::violations([rfmFile(RFM_SERVICE)], ['/->format\s*\(\s*[\'"](?!Y-m-d)/', '/\bdate\s*\(/']))->toBe([]);
});

it('shows the sidebar link only to a holder of metrics.view', function () {
    $sidebar = (string) file_get_contents(rfmFile('resources/js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("can('metrics', 'view')");
});

it('writes the page in strict TypeScript, with no fetch of its own and no raw HTML', function () {
    $source = (string) file_get_contents(rfmFile('resources/js/pages/metrics/rfm.tsx'));

    expect($source)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/')
        ->and($source)->not->toContain('dangerouslySetInnerHTML')
        ->and($source)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b/')
        ->and($source)->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/')
        ->and($source)->toMatch('/export default function \w+\(/');
});
