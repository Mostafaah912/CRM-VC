<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P3-03 boundary: GET /customers/{customer} (routes/internal.php; auth + customers.view) -> Http\Customers\CustomerShowController
| (its FormRequest, ONE service, ONE Inertia response) -> Customers\CustomerShowService (read-only, five queries) ->
| Customers\Support\CustomerShowData (the only place a customer becomes page props: masked phone, no email, no names).
| The service reads the tables of Orders and Metrics with the query builder and never `use`s their classes — a documented
| exception to the PRD §07 dependency table — and only the tables named here.
*/

function csbFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

const CSB_CONTROLLER = 'app/Http/Controllers/Customers/CustomerShowController.php';
const CSB_REQUEST = 'app/Http/Requests/Customers/CustomerShowRequest.php';
const CSB_SERVICE = 'app/Modules/Customers/Services/CustomerShowService.php';
const CSB_DATA = 'app/Modules/Customers/Support/CustomerShowData.php';
const CSB_PAGE = 'resources/js/pages/customers/show.tsx';

/** @return list<string> */
function csbBackend(): array
{
    return array_map(csbFile(...), [CSB_CONTROLLER, CSB_REQUEST, CSB_SERVICE, CSB_DATA]);
}

it('has every file of the customer 360 page', function () {
    $files = [...csbBackend(), csbFile(CSB_PAGE), csbFile('resources/js/lib/format.ts')];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

// ================================================================== controller

it('keeps the controller free of queries, models, config and control flow — one service, one Inertia response', function () {
    $controller = csbFile(CSB_CONTROLLER);
    $code = Scanner::phpCode($controller);

    expect(Scanner::violations([$controller], [
        '/\bDB::/',
        '/::(query|where|find|findOrFail|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
        '/->(where|update|delete|save|insert|upsert|orderBy|latest|select|create)\s*\(/',
        '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
        '/\b(Log|Queue|Cache|Redis|Http|Auth)::/',
        '/\bconfig\s*\(|\benv\s*\(|\bauth\s*\(/',
        '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(|\btry\b|\bcatch\b/',
        '/phone_normalized|PhoneMask|PhoneNormalizer|email/i',
    ]))->toBe([])
        ->and(substr_count($code, 'Inertia::render('))->toBe(1)
        ->and($code)->toContain("Inertia::render('customers/show'");
    preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
    expect($services[0])->toHaveCount(1);
});

it('registers the page as a GET behind auth and customers.view, numeric ids only, named customers.show', function () {
    $routes = Scanner::phpCode(csbFile('routes/internal.php'));

    expect($routes)->toContain("Route::middleware(['auth', 'permission:customers,view'])")
        ->and($routes)->toMatch("/Route::get\('customers\/\{customer\}', CustomerShowController::class\)->whereNumber\('customer'\)->name\('customers\.show'\)/");
});

// ================================================================== the timeline table

it('serves the timeline through CustomerTimelineService once a migration creates customer_events — never by querying the table itself', function () {
    $created = false;

    foreach (glob(csbFile('database/migrations/*.php')) ?: [] as $migration) {
        if (str_contains((string) file_get_contents($migration), 'customer_events')) {
            $created = true;
        }
    }

    $service = Scanner::phpCode(csbFile(CSB_SERVICE));
    $data = Scanner::phpCode(csbFile(CSB_DATA));

    if (! $created) {
        expect($service)->not->toContain('customer_events')
            ->and($service)->not->toContain('CustomerTimelineService')
            ->and($data)->toContain("'timeline' => []");
    } else {
        // The timeline is the timeline service's table: one place decides the order, the cursor and the payload allowlist.
        expect($service)->toContain('CustomerTimelineService')
            ->and($service)->toContain('$this->timeline->page($customer)')
            ->and($service)->not->toContain('customer_events')
            ->and($data)->toContain("'timeline' => \$this->timeline->toArray()");
    }
});

// ================================================================== service: read-only, its own tables

it('reads only: no write, no dispatch, no log, no Woo client, no raw SQL (Rule 7 confines it to Metrics/Analytics)', function () {
    expect(Scanner::violations([csbFile(CSB_SERVICE), csbFile(CSB_DATA)], [
        '/->(create|update|delete|forceDelete|save|insert|insertOrIgnore|upsert|increment|decrement|truncate|flush|push)\s*\(/',
        '/::(create|updateOrCreate|firstOrCreate|insert|upsert|truncate|dispatch)\s*\(/',
        '/\bDB::(statement|unprepared|update|insert|delete|transaction)/',
        '/\b(Log|Queue|Cache|Redis|Http)::|\blogger\s*\(|\bconfig\s*\(|\benv\s*\(/',
        '/\bWooClient\b/',
        '/\b(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw|orWhereRaw)\s*\(|\bDB::(raw|select)\b|new\s+Expression/',
    ]))->toBe([]);
});

it('never `use`s a class of another module besides Customers — it reads their tables, not their code', function () {
    expect(Scanner::violations([csbFile(CSB_SERVICE), csbFile(CSB_DATA), csbFile(CSB_CONTROLLER)], [
        '/App\\\\Modules\\\\(Orders|Metrics|Catalog|Sync|Segments|Analytics|Ai)\\\\/',
    ]))->toBe([]);
});

it('names only customer_metrics, metric_runs, orders and order_items besides customers — and no table that holds audit or identity data', function () {
    $service = Scanner::phpCode(csbFile(CSB_SERVICE));
    preg_match_all('/(?:DB::table|->join)\(\s*\'(\w+)(?: as \w+)?\'/', $service, $named);

    // metric_runs (P4-08): isMetricsStale() reads the latest completed run — never the order tables.
    expect(array_values(array_unique($named[1])))->toEqualCanonicalizing(['customer_metrics', 'metric_runs', 'orders', 'order_items'])
        ->and($service)->not->toMatch('/phone_reveal_logs|customer_identities|customer_addresses|audit_logs|identity_conflicts/');
});

it('counts a purchase from the stored is_realized flag and never from an order status string', function () {
    $service = Scanner::phpCode(csbFile(CSB_SERVICE));

    expect($service)->toContain("'o.is_realized', true")
        ->and($service)->not->toMatch('/[\'"](completed|processing|pending|cancelled|refunded|failed|on-hold)[\'"]/');
});

// ================================================================== the DTO: masking, no email, no float

it('masks the phone through PhoneMask — no inline masking, no normalized phone in the output', function () {
    $data = Scanner::phpCode(csbFile(CSB_DATA));

    expect($data)->toContain("'phone' => PhoneMask::mask(\$c->phone_normalized)")
        ->and(substr_count($data, 'phone_normalized'))->toBe(2)   // the column list and the one PhoneMask call
        ->and(Scanner::violations([csbFile(CSB_DATA)], ['/\b(str_repeat|substr|mb_substr|preg_replace|str_pad)\s*\(/']))->toBe([]);
});

it('never lets an email, a first or last name or the raw phone into what the page receives', function () {
    $sources = [...csbBackend(), csbFile(CSB_PAGE), csbFile('resources/js/types/customers.ts')];

    expect(Scanner::violations($sources, ['/\bemail\b|first_name|last_name|phone_raw_last|phone_raw/i']))->toBe([])
        ->and(Scanner::phpCode(csbFile(CSB_DATA)))->toContain("public const CUSTOMER_COLUMNS = ['id', 'phone_normalized', 'display_name', 'status', 'lifecycle_stage', 'province', 'city', 'first_seen_at']");
});

it('formats money as int and dates through TehranDateTime / JalaliDate — no float, no date()', function () {
    expect(Scanner::violations([csbFile(CSB_SERVICE), csbFile(CSB_DATA)], [
        '/\(float\)|\(double\)|floatval|\bround\s*\(|\bfloor\s*\(|\bceil\s*\(|number_format/',
        '/->format\s*\(|\bdate\s*\(|->toIso8601String|->toDateTimeString|->toDateString/',
    ]))->toBe([])
        ->and(Scanner::phpCode(csbFile(CSB_DATA)))->toContain('TehranDateTime::format(')->toContain('JalaliDate::format(');
});

// ================================================================== the React side

it('writes the page in strict TypeScript, reveals the phone only through PhoneRevealButton, and takes the churn level from the server', function () {
    $page = (string) file_get_contents(csbFile(CSB_PAGE));
    // P4-08: churn rendering (churnLevels, the score) moved into components/metrics/RiskBar.tsx —
    // the assertions about how churn_risk_score/level are handled now apply there, not to show.tsx.
    $riskBar = (string) file_get_contents(csbFile('resources/js/components/metrics/RiskBar.tsx'));

    expect($page)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/')
        ->and($page)->not->toContain('dangerouslySetInnerHTML')
        ->and($page)->not->toMatch('/console\.(log|debug|info)|\bdebugger\b/')
        ->and($page)->not->toMatch('/\b(fetch|axios|XMLHttpRequest)\s*\(/')
        ->and($page)->toContain('@/components/customers/PhoneRevealButton')
        ->and($page)->toMatch('/export default function CustomerShow\(/')
        // Money is Toman (CLAUDE.md §2).
        ->and($page)->not->toContain('ریال');

    // The score is 0..100 and its level is the engine's decision: RiskBar never derives `level` from
    // `score` itself — the only numeric use of `score` is clamping the progress-bar width.
    expect($riskBar)->not->toMatch('/\bscore\)?\s*(>=|<=|>|<)\s*\d/')
        ->and($riskBar)->toContain('churnLevels');
});

it('hides the metrics and risk sections when there is no metrics row', function () {
    $page = (string) file_get_contents(csbFile(CSB_PAGE));

    // Both sections carry their own guard: the four metric cards and the RFM/risk card.
    expect($page)->toMatch('/\{metrics !== null && \(\s*<section/')
        ->and($page)->toMatch('/\{metrics !== null && hasRiskSection\(metrics\) && \(\s*<Card>/');
});
