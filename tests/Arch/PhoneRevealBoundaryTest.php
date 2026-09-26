<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P3-02 boundary: POST /customers/{customer}/reveal-phone (routes/internal.php; auth + customers.view_full_phone + throttle) ->
| Http\Customers\PhoneRevealController (validate via its FormRequest, ONE service, JSON) -> Customers\PhoneRevealService
| (the audit row and the read in one transaction) -> phone_reveal_logs. The controller has no logic; the service never uses the
| Auth facade, never logs; and ONLY the service writes the audit table.
*/

function prFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

/** @return list<string> */
function prBackendFiles(): array
{
    return array_map(prFile(...), [
        'app/Http/Controllers/Customers/PhoneRevealController.php',
        'app/Http/Requests/Customers/PhoneRevealRequest.php',
        'app/Modules/Customers/Services/PhoneRevealService.php',
        'app/Modules/Customers/Models/PhoneRevealLog.php',
    ]);
}

it('has every file of the phone reveal', function () {
    $files = [
        ...prBackendFiles(),
        prFile('resources/js/components/customers/PhoneRevealButton.tsx'),
        glob(prFile('database/migrations/*_create_phone_reveal_logs_table.php'))[0] ?? '',
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
});

it('names the migration as the task asked', function () {
    expect(is_file(prFile('database/migrations/2026_09_20_120000_create_phone_reveal_logs_table.php')))->toBeTrue();
});

it('keeps the controller free of queries, models, config and control flow — one service, one JSON response', function () {
    $controller = prFile('app/Http/Controllers/Customers/PhoneRevealController.php');
    $code = Scanner::phpCode($controller);

    expect(Scanner::violations([$controller], [
        '/\bDB::/',
        '/::(query|where|find|findOrFail|create|firstOrCreate|insert|upsert|all|count|paginate)\s*\(/',
        '/->(where|update|delete|save|insert|upsert|orderBy|latest|select|create)\s*\(/',
        '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
        '/\b(Log|Queue|Cache|Redis|Http|Auth)::/',
        '/\bconfig\s*\(|\benv\s*\(|\bauth\s*\(|\blogger\s*\(|\binfo\s*\(/',
        '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(|\btry\b|\bcatch\b/',
        '/phone_normalized|PhoneMask|PhoneNormalizer|user_agent|userAgent|->ip\s*\(/',
    ]))->toBe([])
        ->and(substr_count($code, 'response()->json('))->toBe(1);
    preg_match_all('/use App\\\\Modules\\\\\w+\\\\Services\\\\\w+;/', $code, $services);
    expect($services[0])->toHaveCount(1);
});

it('registers the reveal as a POST in routes/internal.php, behind auth, customers.view_full_phone and its own throttle — the only route that reveals', function () {
    $routes = Scanner::phpCode(prFile('routes/internal.php'));

    // Six POSTs in the file now: this reveal, P3-05's notes store, P5-05's segment rule preview, and
    // P5-06's segment store/update/evaluate (none of the new ones reveal a phone).
    expect(substr_count($routes, 'Route::post('))->toBe(6)
        ->and(substr_count($routes, 'reveal-phone'))->toBe(2) // the URL and the route name, both in the ONE reveal route
        ->and($routes)->toContain("'permission:customers,view_full_phone'")
        ->and($routes)->toContain("'throttle:10,1,phone-reveal'")
        ->and($routes)->toContain("'customers/{customer}/reveal-phone'")
        ->and($routes)->toContain("->name('customers.reveal-phone')")
        ->and($routes)->toContain("->whereNumber('customer')")
        ->and(substr_count($routes, 'Route::delete('))->toBe(2) // P3-05's note delete, P5-06's segment delete
        ->and($routes)->not->toMatch('/Route::(put|patch|any|match|resource|apiResource)\b/');

    // one group carries all three, and the route sits inside it
    expect($routes)->toMatch("/Route::middleware\(\['auth', 'permission:customers,view_full_phone', 'throttle:10,1,phone-reveal'\]\)/");
});

it('takes who is asking from the request: the service never touches the Auth facade or the auth helper', function () {
    $service = prFile('app/Modules/Customers/Services/PhoneRevealService.php');

    expect(Scanner::violations([$service], [
        '/\bAuth::/',
        '/\bauth\s*\(/',
        '/Illuminate\\\\Support\\\\Facades\\\\Auth\b/',
    ]))->toBe([])
        ->and(Scanner::phpCode($service))->toContain('$request->user()');
});

it('never logs anything in the service, and never names a phone in an error', function () {
    $service = prFile('app/Modules/Customers/Services/PhoneRevealService.php');

    expect(Scanner::violations([$service], [
        '/\bLog::/',
        '/\blogger\s*\(|\binfo\s*\(|\breport\s*\(|\bdump\s*\(|\bdd\s*\(|\berror_log\s*\(/',
        '/Illuminate\\\\Support\\\\Facades\\\\Log\b/',
        '/NotFoundHttpException\([^)]*\$/',
    ]))->toBe([]);
});

it('does the audit insert and the read in one transaction, and checks a trashed or phone-less customer before either', function () {
    $code = Scanner::phpCode(prFile('app/Modules/Customers/Services/PhoneRevealService.php'));

    expect($code)->toContain('DB::transaction(')
        ->and($code)->toContain('->trashed()')
        ->and($code)->toContain('NotFoundHttpException')
        ->and($code)->toContain('mb_substr(')
        ->and(strpos($code, '->trashed()'))->toBeLessThan(strpos($code, 'DB::transaction('));
});

it('lets ONLY the reveal service write phone_reveal_logs', function () {
    $violations = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        $relative = Scanner::relative($file);

        if (in_array($relative, ['app/Modules/Customers/Services/PhoneRevealService.php', 'app/Modules/Customers/Models/PhoneRevealLog.php'], true)) {
            continue;
        }

        foreach (Scanner::violations([$file], ['/PhoneRevealLog\b/', '/phone_reveal_logs/']) as $hit) {
            $violations[] = "{$relative}: {$hit}";
        }
    }

    expect($violations)->toBe([]);
});

it('releases nothing but the phone: the service returns a string and the controller answers with that one key', function () {
    expect(Scanner::phpCode(prFile('app/Http/Controllers/Customers/PhoneRevealController.php')))->toMatch("/response\(\)->json\(\[\s*'phone'\s*=>/");
});

it('writes the button in strict TypeScript that never leaks the number: no any, no console, no window, no storage', function () {
    $button = (string) file_get_contents(prFile('resources/js/components/customers/PhoneRevealButton.tsx'));

    expect($button)->toMatch('/export (default )?function \w+\(/')
        ->and($button)->not->toMatch('/:\s*any\b|\bas\s+any\b|<any>|Array<any>|@ts-(ignore|nocheck|expect-error)/')
        ->and($button)->not->toContain('dangerouslySetInnerHTML')
        ->and($button)->not->toMatch('/console\.\w+|\bdebugger\b/')
        ->and($button)->not->toMatch('/\bwindow\b|\bglobalThis\b|\bdocument\.(?!cookie)/')
        ->and($button)->not->toMatch('/localStorage|sessionStorage|indexedDB|caches\.|navigator\.clipboard/')
        ->and($button)->toContain('X-XSRF-TOKEN')
        ->and($button)->toContain("Accept: 'application/json'")
        ->and($button)->toContain("method: 'POST'");
});

it('says what went wrong in Persian for 429, 403 and everything else, and nothing more', function () {
    $button = (string) file_get_contents(prFile('resources/js/components/customers/PhoneRevealButton.tsx'));

    expect($button)->toContain('تعداد درخواست‌ها بیش از حد مجاز است')
        ->and($button)->toContain('دسترسی ندارید')
        ->and($button)->toContain('خطا در نمایش شماره')
        ->and($button)->toContain('برای مشاهده کلیک کنید')
        ->and($button)->toContain('429')
        ->and($button)->toContain('403');
});

it('uses the button in the customer list, and only offers the click to a viewer who may reveal', function () {
    $page = (string) file_get_contents(prFile('resources/js/pages/customers/index.tsx'));

    expect($page)->toContain('@/components/customers/PhoneRevealButton')
        ->and($page)->toContain('<PhoneRevealButton')
        ->and($page)->toContain("can('customers', 'view_full_phone')");
});
