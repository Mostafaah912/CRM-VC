<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P2-09 boundary: POST /webhooks/woo -> WooWebhookRequest (FormRequest: WooWebhookVerifier -> 401) -> WooWebhookController
| (ONE call) -> Sync\WooWebhookService (topic, dedupe, ONE SyncEntityJob). The controller has no logic; the body is never
| parsed or logged; the signature is compared in constant time; the secret comes from config only.
*/

function hookFile(string $relative): string
{
    return Scanner::root().'/'.$relative;
}

/** @return list<string> */
function webhookFiles(): array
{
    return array_map(hookFile(...), [
        'app/Http/Controllers/WooWebhookController.php',
        'app/Http/Requests/WooWebhookRequest.php',
        'app/Modules/Sync/Services/WooWebhookService.php',
        'app/Modules/Sync/Services/WooWebhookVerifier.php',
    ]);
}

it('has the four webhook files', function () {
    expect(array_map('is_file', webhookFiles()))->each->toBeTrue();
});

it('keeps the controller free of database access and models, calling exactly one service', function () {
    $controller = hookFile('app/Http/Controllers/WooWebhookController.php');

    expect(Scanner::violations([$controller], [
        '/\bDB::/',
        '/::(query|where|find|create|firstOrCreate|insert|insertOrIgnore|upsert|all|count)\s*\(/',
        '/->(where|update|delete|save|insert|insertOrIgnore|upsert)\s*\(/',
        '/App\\\\Modules\\\\\w+\\\\Models\\\\/',
        '/\bLog::/',
        '/\bif\s*\(|\bforeach\s*\(|\bmatch\s*\(|\bswitch\s*\(/',
    ]))->toBe([])
        ->and(substr_count(Scanner::phpCode($controller), '$webhooks->receive('))->toBe(1);
});

it('never reads or parses the body outside the signature check', function () {
    $files = webhookFiles();

    expect(Scanner::violations($files, [
        '/\bjson_decode\s*\(/',
        '/\$\w+->(json|input|all|post|query|except|only|collect|validated|safe|getPayload|toArray|request)\b/',
        '/\$_(POST|GET|REQUEST)\b/',
        '/\bfile_get_contents\s*\(\s*[\'"]php:\/\/input/',
    ]))->toBe([]);

    $withContent = array_filter($files, fn (string $f) => str_contains(Scanner::phpCode($f), 'getContent('));
    expect(array_map(fn (string $f) => basename($f), array_values($withContent)))->toBe(['WooWebhookRequest.php']);
});

it('reads only the three Woo headers, and hands the validator nothing', function () {
    $headers = [];

    foreach (webhookFiles() as $file) {
        preg_match_all('/->headers->get\(\s*[\'"]([^\'"]+)[\'"]/', Scanner::phpCode($file), $m);
        array_push($headers, ...$m[1]);
    }

    sort($headers);

    expect($headers)->toBe(['X-WC-Webhook-Delivery-ID', 'X-WC-Webhook-Signature', 'X-WC-Webhook-Topic'])
        ->and(Scanner::phpCode(hookFile('app/Http/Requests/WooWebhookRequest.php')))
        ->toMatch('/function\s+validationData\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*\[\s*\]\s*;/');
});

it('never logs anything drawn from the request or the secret', function () {
    expect(Scanner::violations(webhookFiles(), [
        '/Log::\w+\([^;]*(getContent\(|->header\(|config\(|->all\(|secret|payload|\$body)/is',
        '/\b(logger|info|report|dump|dd)\s*\(/',
    ]))->toBe([]);
});

it('compares the signature with hash_equals only, computed as a raw HMAC-SHA256 then base64', function () {
    $verifier = Scanner::phpCode(hookFile('app/Modules/Sync/Services/WooWebhookVerifier.php'));

    expect($verifier)->toContain('hash_equals(')
        ->and($verifier)->toContain("hash_hmac('sha256'")
        ->and($verifier)->toContain('base64_encode(')
        ->and(Scanner::violations([hookFile('app/Modules/Sync/Services/WooWebhookVerifier.php')], [
            '/\$(signature|expected)\s*[!=]==?\s*\$/',
            '/\$\w+\s*[!=]==?\s*\$(signature|expected)\b/',
            '/\bstrcmp\s*\(|\bsubstr_compare\s*\(|\bstrncmp\s*\(/',
            '/\bmd5\s*\(|\bsha1\s*\(/',
        ]))->toBe([]);
});

it('takes the secret and the allowlist from config only — never env(), never a literal', function () {
    expect(Scanner::violations(webhookFiles(), [
        '/\benv\s*\(/',
        '/\bgetenv\s*\(|\$_ENV|\$_SERVER\[/',
        '/[\'"]whsec/i',
    ]))->toBe([])
        ->and(Scanner::phpCode(hookFile('app/Modules/Sync/Services/WooWebhookVerifier.php')))
        ->toContain("config('woo.webhook_secret')")
        ->toContain("config('woo.webhook_allowed_ips')");
});

it('keeps the webhook services to Sync\'s own module: SyncEntityJob only, no other module, no HTTP, no Woo client', function () {
    $service = hookFile('app/Modules/Sync/Services/WooWebhookService.php');

    expect(Scanner::violations([$service, hookFile('app/Modules/Sync/Services/WooWebhookVerifier.php')], [
        '/App\\\\Modules\\\\(?!Sync\\\\)/',
        '/\b(Http|Redis)::/',
        '/\bWooClient\b|OrderSyncService|RefundSyncService|SyncService\b|SyncPageJob/',
    ]))->toBe([])
        ->and(substr_count(Scanner::phpCode($service), 'new SyncEntityJob('))->toBe(1)
        ->and(Scanner::phpCode($service))->toContain("QUEUE = 'critical'")
        ->and(Scanner::phpCode($service))->toContain('onQueue(self::QUEUE)');
});

it('writes the deliveries table only through the webhook service', function () {
    $violations = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        $relative = Scanner::relative($file);

        if ($relative === 'app/Modules/Sync/Services/WooWebhookService.php' || $relative === 'app/Modules/Sync/Models/WooWebhookDelivery.php') {
            continue;
        }

        foreach (Scanner::violations([$file], ['/WooWebhookDelivery\b/']) as $hit) {
            $violations[] = "{$relative}: {$hit}";
        }
    }

    expect($violations)->toBe([]);
});

it('writes topic and outcome only through enums', function () {
    expect(Scanner::violations([hookFile('app/Modules/Sync/Services/WooWebhookService.php')], [
        '/[\'"](order|woocommerce)\.(created|updated|deleted)[\'"]/',
        '/[\'"](accepted|duplicate|ignored)[\'"]/',
    ]))->toBe([]);
});

it('registers the route in its own file, outside the web group, and only there', function () {
    $routes = (string) file_get_contents(hookFile('routes/webhooks.php'));
    $web = (string) file_get_contents(hookFile('routes/web.php'));
    $bootstrap = (string) file_get_contents(hookFile('bootstrap/app.php'));

    expect($routes)->toContain("Route::post('webhooks/woo'")
        ->and(substr_count($routes, 'Route::'))->toBe(1)
        ->and($web)->not->toContain('webhooks')
        ->and($bootstrap)->toContain('routes/webhooks.php')
        ->and($routes)->not->toMatch('/middleware\s*\(\s*\[?\s*[\'"]web[\'"]/');
});

it('keeps the secret out of the repository: the example env lists the settings, empty', function () {
    $example = (string) file_get_contents(hookFile('.env.example'));

    expect($example)->toMatch('/^WOO_WEBHOOK_SECRET=\s*$/m')
        ->and($example)->toMatch('/^WOO_WEBHOOK_ALLOWED_IPS=\s*$/m')
        ->and($example)->toMatch('/^TRUSTED_PROXIES=\s*$/m');
});

it('adds no refund webhook, no registration automation and no webhook command', function () {
    $files = Scanner::phpFiles(['app']);
    $names = array_map(fn (string $f) => basename($f), $files);

    expect(array_filter($names, fn (string $n) => preg_match('/Webhook.*Refund|Refund.*Webhook|Register.*Webhook|Webhook.*Register/i', $n) === 1))->toBe([])
        ->and(Scanner::violations(Scanner::phpFiles(['app/Console']), ['/webhook/i']))->toBe([]);
});

it('feeds the three settings from their documented env names, through config files only', function () {
    $woo = Scanner::phpCode(hookFile('config/woo.php'));
    $proxy = Scanner::phpCode(hookFile('config/trustedproxy.php'));

    expect($woo)->toContain("'webhook_secret' => env('WOO_WEBHOOK_SECRET')")
        ->and($woo)->toContain("'webhook_allowed_ips' => env('WOO_WEBHOOK_ALLOWED_IPS'")
        ->and($proxy)->toContain("env('TRUSTED_PROXIES')");
});
