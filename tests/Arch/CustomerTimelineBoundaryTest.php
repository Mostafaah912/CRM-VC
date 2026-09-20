<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P3-04 boundary: GET /customers/{customer}/timeline (routes/internal.php; auth + customers.view) ->
| Http\Customers\CustomerTimelineController (its FormRequest, ONE service, JSON) -> Customers\CustomerTimelineService (the ONLY place
| the cursor is encoded or decoded, the ONLY place a payload is filtered, read-only) -> customer_events. The first page also rides
| in the Customer 360 props through CustomerShowService. The browser never receives the raw stored date or a non-allowlisted key.
*/

function ctbFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

const CTB_CONTROLLER = 'app/Http/Controllers/Customers/CustomerTimelineController.php';
const CTB_REQUEST = 'app/Http/Requests/Customers/CustomerTimelineRequest.php';
const CTB_SERVICE = 'app/Modules/Customers/Services/CustomerTimelineService.php';
const CTB_PAGE_VO = 'app/Modules/Customers/Support/TimelinePage.php';
const CTB_COMPONENT = 'resources/js/components/customers/CustomerTimeline.tsx';

it('has every file of the timeline, and the migration is named as the task asked', function () {
    $files = [
        ctbFile(CTB_CONTROLLER), ctbFile(CTB_REQUEST), ctbFile(CTB_SERVICE), ctbFile(CTB_PAGE_VO), ctbFile(CTB_COMPONENT),
        ctbFile('app/Modules/Customers/Models/CustomerEvent.php'),
        ctbFile('app/Modules/Customers/Enums/CustomerEventType.php'),
        ctbFile('database/migrations/2026_09_20_130000_create_customer_events_table.php'),
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

// ================================================================== controller

it('keeps the controller free of queries, models, cursor handling, config and control flow — one service, one JSON response', function () {
    $controller = ctbFile(CTB_CONTROLLER);
    $code = Scanner::phpCode($controller);

    expect(Scanner::violations([$controller], [
        '/\bDB::/',
        '/::(query|where|find|findOrFail|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
        '/->(where|update|delete|save|insert|upsert|orderBy|latest|select|create|filter|map)\s*\(/',
        '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
        '/\b(Log|Queue|Cache|Redis|Http|Auth)::/',
        '/\bconfig\s*\(|\benv\s*\(|\bauth\s*\(/',
        '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(|\btry\b|\bcatch\b/',
        '/base64|json_decode|json_encode|(en|de)codeCursor|happened_at|payload|PAYLOAD_KEYS/i',
    ]))->toBe([])
        ->and(substr_count($code, 'response()->json('))->toBe(1)
        ->and($code)->not->toContain('Inertia');
    preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
    expect($services[0])->toHaveCount(1);
});

it('registers the timeline as a GET behind auth and customers.view, numeric ids only, named customers.timeline', function () {
    $routes = Scanner::phpCode(ctbFile('routes/internal.php'));

    expect($routes)->toContain("Route::middleware(['auth', 'permission:customers,view'])")
        ->and($routes)->toMatch("/Route::get\('customers\/\{customer\}\/timeline', CustomerTimelineController::class\)->whereNumber\('customer'\)->name\('customers\.timeline'\)/");
});

it('refuses a page size above 50 in the request, and the limit is the service constant', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));
    $request = Scanner::phpCode(ctbFile(CTB_REQUEST));

    expect($service)->toMatch('/public const MAX_PER_PAGE = 50;/')
        ->and($request)->toContain("'max:'.CustomerTimelineService::MAX_PER_PAGE")
        ->and($request)->toContain("'integer'");
});

// ================================================================== the cursor lives in one place

it('encodes and decodes the cursor ONLY in CustomerTimelineService — nowhere else in the backend or the browser code', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));
    $customersBackend = array_filter(
        Scanner::phpFiles(['app/Modules/Customers', 'app/Http/Controllers/Customers', 'app/Http/Requests/Customers']),
        fn (string $file) => ! str_ends_with($file, 'CustomerTimelineService.php'),
    );

    expect($service)->toContain('function encodeCursor(')->toContain('function decodeCursor(')
        ->toContain('base64_encode(')->toContain('base64_decode(')->toContain('JSON_THROW_ON_ERROR')
        ->and(Scanner::violations(array_values($customersBackend), ['/base64_(en|de)code|encodeCursor|decodeCursor|urlsafe/i']))->toBe([])
        ->and(Scanner::violations([ctbFile(CTB_COMPONENT), ctbFile('resources/js/pages/customers/show.tsx')], ['/\batob\s*\(|\bbtoa\s*\(|JSON\.parse\s*\(|base64/i'], false))->toBe([]);
});

it('decodes the cursor strictly and uses it only as bound values — never as SQL', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));

    expect($service)->toContain("array_keys(\$data) !== ['happened_at', 'id']")
        ->and($service)->toContain('$id < 1')
        ->and($service)->toContain('base64_decode(')->toContain(', true)') // strict mode
        ->and(Scanner::violations([ctbFile(CTB_SERVICE)], [
            '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw|orWhereRaw)\s*\(|\bDB::|new\s+Expression/',
        ]))->toBe([])
        // The position enters the query as ONE bound row comparison (an index range start) and nothing built from a string.
        ->and($service)->toContain("->whereRowValues(['happened_at', 'id'], '<', [\$stamp, \$id])");
});

it('orders newest first by happened_at then id, with one extra row read to know has_more', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));

    expect($service)->toContain("->orderByDesc('happened_at')")->toContain("->orderByDesc('id')")
        ->and($service)->not->toMatch("/->orderBy\(\s*'(happened_at|id)'/")
        ->and($service)->toContain('->limit($limit + 1)');
});

// ================================================================== the payload allowlist

it('lets a payload through ONLY via the five allowlisted keys, iterated from the constant', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));
    preg_match("/PAYLOAD_KEYS = \[([^\]]*)\]/", $service, $m);
    preg_match_all("/'(\w+)'/", $m[1] ?? '', $keys);

    expect($keys[1])->toBe(['order_id', 'note', 'old_status', 'new_status', 'woo_order_id'])
        ->and($service)->toContain('foreach (self::PAYLOAD_KEYS as $key)')
        // The one place an event's payload is put in the output is the filter.
        ->and(substr_count($service, "'payload' =>"))->toBe(1)
        ->and($service)->toContain("'payload' => \$this->filterPayload(\$row->payload)")
        // A stored payload is never spread, merged or returned whole.
        ->and($service)->not->toMatch('/\.\.\.\s*\$(row->)?payload|array_merge\([^)]*payload|return\s+\$payload;|\$row->payload,?\s*\n?\s*\]/');
});

it('takes only plain scalars from an allowed key, so a nested value cannot carry another key out', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));

    expect($service)->toContain('is_string($value) || is_int($value) || is_bool($value) || $value === null');
});

it('never puts a payload anywhere but through the timeline service: the page object and the 360 DTO carry events as already shaped', function () {
    expect(Scanner::violations([ctbFile(CTB_PAGE_VO), ctbFile('app/Modules/Customers/Support/CustomerShowData.php'), ctbFile('app/Modules/Customers/Services/CustomerShowService.php')], [
        '/customer_events|CustomerEvent\b|->payload\b|[\'"]payload[\'"]\s*=>/',
    ]))->toBe([]);
});

// ================================================================== dates

it('never sends the raw stored date: every event carries its Jalali form and its ISO form, and nothing else of the instant', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));

    expect($service)->toContain("'happened_at_jalali' => TehranDateTime::format(")
        ->and($service)->toContain("'happened_at_iso' => \$at->utc()->toIso8601ZuluString()")
        // The bare key appears once — inside the cursor's own JSON, which the browser cannot read — and never in an event.
        ->and(substr_count($service, "'happened_at' =>"))->toBe(1)
        ->and(Scanner::violations([ctbFile(CTB_PAGE_VO), ctbFile('app/Modules/Customers/Support/CustomerShowData.php')], ['/[\'"]happened_at[\'"]/']))->toBe([])
        ->and(Scanner::violations([ctbFile(CTB_COMPONENT), ctbFile('resources/js/types/customers.ts'), ctbFile('resources/js/pages/customers/show.tsx')], ['/\bhappened_at\b(?!_(jalali|iso))/'], false))->toBe([]);
});

// ================================================================== read-only, its own module

it('reads only: no write, no dispatch, no log, no raw SQL, no other module\'s class besides Customers\'', function () {
    expect(Scanner::violations([ctbFile(CTB_SERVICE), ctbFile(CTB_PAGE_VO)], [
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate|flush|push)\s*\(/',
        '/::(create|updateOrCreate|firstOrCreate|insert|upsert|truncate|dispatch)\s*\(/',
        '/\b(Log|Queue|Cache|Redis|Http)::|\blogger\s*\(|\bconfig\s*\(|\benv\s*\(/',
        '/App\\\\Modules\\\\(Orders|Metrics|Catalog|Sync|Segments|Analytics|Ai|Core)\\\\/',
    ]))->toBe([]);
});

it('reads the timeline through its own model, CustomerEvent, and no other table', function () {
    $service = Scanner::phpCode(ctbFile(CTB_SERVICE));

    expect($service)->toContain('CustomerEvent::query()')
        ->and($service)->not->toMatch('/[\'"](orders|order_items|customer_metrics|phone_reveal_logs|audit_logs)[\'"]/');
});

// ================================================================== the React side

it('writes the component in strict TypeScript, asks the endpoint through the generated route, and renders payload text as text', function () {
    $source = (string) file_get_contents(ctbFile(CTB_COMPONENT));

    expect($source)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/')
        ->and($source)->not->toContain('dangerouslySetInnerHTML')
        ->and($source)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b/')
        ->and($source)->toContain("from '@/routes/customers'")
        ->and($source)->toContain('timeline.url(customerId')
        // No hand-written address: the route comes from Wayfinder.
        ->and($source)->not->toMatch('/[\'"`]\/customers\//')
        ->and($source)->toContain('export function CustomerTimeline(')
        // The load-more state machine: idle, loading, error.
        ->and($source)->toContain("status: 'loading'")->toContain("status: 'error'")->toContain('disabled={load.status')
        // The cursor is handed back exactly as received.
        ->and($source)->toContain('query: { cursor: nextCursor }');
});

it('shows the timeline card on the 360 page only when there are events, from the page\'s own props', function () {
    $page = (string) file_get_contents(ctbFile('resources/js/pages/customers/show.tsx'));

    expect($page)->toContain('@/components/customers/CustomerTimeline')
        ->and($page)->toMatch('/\{timeline\.data\.length > 0 && \(\s*<Card>/')
        ->and($page)->toContain('initialData={timeline}')
        ->and($page)->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/');
});
