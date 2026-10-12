<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P7-02: the AI provider layer is private to the Ai module. Only the container binding
| (app/Providers/AppServiceProvider.php) may name AiProvider/AiProviderFactory from outside; no one outside
| the Ai module may ever name a concrete driver. No test reaches the network, no key or model id is hardcoded.
*/

function aiOutsideFiles(): array
{
    $files = Scanner::phpFiles(['app', 'routes', 'bootstrap/app.php', 'config', 'database']);

    return array_values(array_filter($files, fn (string $f) => ! str_starts_with(Scanner::relative($f), 'app/Modules/Ai/')));
}

it('lets no code outside the Ai module name a driver or any Ai class except the container binding and the P7-01 setup command', function () {
    $files = array_values(array_filter(
        aiOutsideFiles(),
        fn (string $f) => ! in_array(Scanner::relative($f), ['app/Providers/AppServiceProvider.php', 'app/Console/Commands/AiSetupReadonlyRoleCommand.php'], true),
    ));

    expect(Scanner::violations($files, ['/App\\\\Modules\\\\Ai\\\\/']))->toBeEmpty();
});

it('lets the container binding name only AiProvider and AiProviderFactory — never a driver', function () {
    $provider = [Scanner::root().'/app/Providers/AppServiceProvider.php'];

    expect(Scanner::violations($provider, ['/Ai\\\\Drivers\\\\/', '/\b(Gemini|Anthropic|OpenAi)Driver\b/']))->toBeEmpty();
});

it('keeps the HTTP client inside the Ai drivers', function () {
    $files = array_values(array_filter(
        Scanner::phpFiles(['app/Modules/Ai']),
        fn (string $f) => ! str_starts_with(Scanner::relative($f), 'app/Modules/Ai/Drivers/'),
    ));

    expect(Scanner::violations($files, ['/\bHttp::/', '/Illuminate\\\\Support\\\\Facades\\\\Http\b/']))->toBeEmpty();
});

it('lets only the factory and the drivers\' own tests reference a concrete driver', function () {
    $files = array_values(array_filter(
        Scanner::phpFiles(['app/Modules/Ai']),
        fn (string $f) => ! str_starts_with(Scanner::relative($f), 'app/Modules/Ai/Drivers/')
            && Scanner::relative($f) !== 'app/Modules/Ai/Services/AiProviderFactory.php',
    ));

    expect(Scanner::violations($files, ['/\b(Gemini|Anthropic|OpenAi)Driver\b/']))->toBeEmpty();
});

it('lets no test that can build a real driver run without Http::preventStrayRequests()', function () {
    $unguarded = [];

    foreach (Scanner::phpFiles(['tests']) as $file) {
        $relative = Scanner::relative($file);

        if (str_starts_with($relative, 'tests/Arch/')) {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (preg_match('/\b(Gemini|Anthropic|OpenAi)Driver\b|AiProviderFactory/', $source) === 1
            && ! str_contains($source, 'Http::preventStrayRequests()')) {
            $unguarded[] = $relative;
        }
    }

    expect($unguarded)->toBeEmpty();
});

it('never hardcodes a key or a model id: config/ai.php reads both from env, code names no model', function () {
    $config = [Scanner::root().'/config/ai.php'];

    expect(Scanner::violations($config, ["/'api_key'\s*=>\s*'[^']/", "/'model'\s*=>\s*'[^']/"]))->toBeEmpty()
        ->and(Scanner::violations(
            Scanner::phpFiles(['app/Modules/Ai']),
            ['/[\'"](gemini|claude|gpt|o[134])-[\w.-]*[\'"]/i'],
        ))->toBeEmpty();
});
