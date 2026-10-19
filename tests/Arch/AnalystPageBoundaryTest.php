<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P7-09a: the «تحلیلگر هوشمند» screen. Controllers do three things only (validate via FormRequest, call ONE Service, return Inertia/JSON): no DB, no
| models, no config, no business conditionals, no provider/HTTP code. The routes sit behind auth + an ai permission, are read-only except ONE queueing
| POST, and nothing on this surface can return a prompt, a context or a raw payload.
*/

function analystControllers(): array
{
    return Scanner::phpFiles(['app/Http/Controllers/Ai']);
}

it('has exactly the four analyst controllers, each a thin invokable', function () {
    $names = array_map(fn (string $f) => Scanner::relative($f), analystControllers());

    expect($names)->toBe([
        'app/Http/Controllers/Ai/AnalystGenerateController.php',
        'app/Http/Controllers/Ai/AnalystHistoryController.php',
        'app/Http/Controllers/Ai/AnalystPageController.php',
        'app/Http/Controllers/Ai/AnalystStatusController.php',
    ]);
});

it('keeps the controllers free of database, models, config, conditionals and provider code', function () {
    expect(Scanner::violations(analystControllers(), [
        '/\bDB::/',
        '/::query\s*\(|->where\s*\(|->get\s*\(\)/',
        '/App\\\\Modules\\\\Ai\\\\Models\\\\/',
        '/\bconfig\s*\(|\benv\s*\(/',
        '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(|\?[^?:]*:/',
        '/\bHttp::|Drivers\\\\|AiProvider\b|BudgetGuard|AnalystService\b/',
        '/\b(Cache|Queue|RateLimiter)::/',
    ]))->toBe([]);
});

it('has one Service per controller and one render/response each', function () {
    foreach (analystControllers() as $file) {
        $code = Scanner::phpCode($file);
        preg_match_all('/use App\\\\Modules\\\\Ai\\\\Services\\\\\w+;/', $code, $services);

        expect($services[0])->toHaveCount(1, Scanner::relative($file).' uses more than one Service');
    }

    expect(substr_count(Scanner::phpCode(Scanner::root().'/app/Http/Controllers/Ai/AnalystPageController.php'), 'Inertia::render('))->toBe(1);
});

it('registers the analyst routes in routes/ai.php: three reads behind ai.view, ONE queueing POST behind ai.request with its own throttle bucket', function () {
    $routes = Scanner::phpCode(Scanner::root().'/routes/ai.php');

    expect(substr_count($routes, 'Route::get('))->toBe(3)
        ->and(substr_count($routes, 'Route::post('))->toBe(1)
        ->and($routes)->not->toMatch('/Route::(put|patch|delete|any|match|resource|apiResource)\b/')
        ->and($routes)->toContain("'permission:ai,view'")->toContain("'permission:ai,request'")->toContain("'throttle:20,1,ai-generate'")
        ->and(substr_count($routes, "'auth'"))->toBe(2)
        ->and((string) file_get_contents(Scanner::root().'/routes/web.php'))->toContain("require __DIR__.'/ai.php';");
});

it('never puts a prompt, a context, a cache key or a raw payload on the screen\'s data', function () {
    $files = [
        Scanner::root().'/app/Modules/Ai/Services/AnalystInsightPresenter.php',
        Scanner::root().'/app/Modules/Ai/Services/AnalystPageService.php',
        Scanner::root().'/app/Modules/Ai/Services/ManualGenerationService.php',
    ];

    expect(Scanner::violations($files, ['/[\'"]cache_key[\'"]\s*=>/', '/[\'"](prompt|system_prompt|context|raw|response_body)[\'"]\s*=>/', '/[\'"]payload[\'"]\s*=>/', '/PromptRepository|ContextBuilder|BuiltContext/']))->toBe([]);
});

// A config KEY like woo.status_labels is only data; what is forbidden is Woo client / Sync code.
it('writes nothing to WooCommerce and sends nothing: the analyst page code has no Woo/SMS/campaign/message code', function () {
    $files = array_merge(analystControllers(), [Scanner::root().'/app/Modules/Ai/Services/ManualGenerationService.php', Scanner::root().'/app/Modules/Ai/Services/AnalystPageService.php']);

    expect(Scanner::violations($files, ['/\bWoo[A-Z]\w*|Modules\\\\Sync|Sms|Campaign|Mail::|Notification::|Telegram|WhatsApp/i']))->toBe([]);
});

it('renders model text only as text in the analyst UI: no raw HTML injection, no markdown-to-HTML, no console output', function () {
    $files = Scanner::files(['resources/js/pages/ai', 'resources/js/components/ai'], 'ts,tsx', []);

    expect($files)->not->toBeEmpty()
        ->and(Scanner::violations($files, ['/dangerouslySetInnerHTML|innerHTML|insertAdjacentHTML/', '/\b(marked|markdown-it|remark|showdown|DOMPurify|react-markdown)\b/i', '/\bconsole\./'], stripPhpComments: false))->toBe([]);
});
