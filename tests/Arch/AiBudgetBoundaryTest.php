<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P7-03 / GATE 4: there is no path to a real AI provider that skips BudgetGuard. The container binding wraps the
| factory's driver in BudgetedAiProvider; nothing else may build or hold a raw driver; only AiUsageService writes
| the usage ledger. No test reaches the network (the P7-02 test enforces Http::preventStrayRequests()).
*/

function aiRelativeFiles(string $dir): array
{
    return array_map(fn (string $f) => Scanner::relative($f), Scanner::phpFiles([$dir]));
}

it('binds AiProvider only as BudgetedAiProvider wrapping the factory driver', function () {
    $code = Scanner::phpCode(Scanner::root().'/app/Providers/AppServiceProvider.php');

    expect($code)->toContain('bind(AiProvider::class')
        ->and($code)->toMatch('/bind\(AiProvider::class[^;]*new BudgetedAiProvider\(\s*fn \(\): AiProvider => \$app->make\(AiProviderFactory::class\)->make\(\)/s')
        ->and(substr_count($code, 'AiProviderFactory::class'))->toBe(1);
});

it('lets only the factory and the container binding mention AiProviderFactory', function () {
    $holders = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        if (str_contains(Scanner::phpCode($file), 'AiProviderFactory')) {
            $holders[] = Scanner::relative($file);
        }
    }

    expect($holders)->toEqualCanonicalizing(['app/Modules/Ai/Services/AiProviderFactory.php', 'app/Providers/AppServiceProvider.php']);
});

it('has exactly three AiProvider implementations: the budget decorator, the fake, and the abstract HTTP driver base', function () {
    $implementers = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        if (preg_match('/\bimplements\s+[^{]*\bAiProvider\b/', Scanner::phpCode($file)) === 1) {
            $implementers[] = Scanner::relative($file);
        }
    }

    expect($implementers)->toEqualCanonicalizing([
        'app/Modules/Ai/Drivers/HttpAiDriver.php',
        'app/Modules/Ai/Services/BudgetedAiProvider.php',
        'app/Modules/Ai/Services/FakeAiProvider.php',
    ]);
});

it('makes BudgetedAiProvider reserve before calling the inner provider and never call it otherwise (the inner is resolved lazily, on first use)', function () {
    $code = Scanner::phpCode(Scanner::root().'/app/Modules/Ai/Services/BudgetedAiProvider.php');

    expect(strpos($code, '->reserve('))->toBeInt()
        ->and(strpos($code, '->provider()->generate('))->toBeGreaterThan(strpos($code, '->reserve('))
        ->and(substr_count($code, '->generate('))->toBe(1);
});

it('lets only AiUsageService write ai_usage_daily', function () {
    $writers = [];

    foreach (Scanner::phpFiles(['app']) as $file) {
        if (preg_match('/AiUsageDaily|ai_usage_daily/', Scanner::phpCode($file)) === 1) {
            $writers[] = Scanner::relative($file);
        }
    }

    expect($writers)->toEqualCanonicalizing(['app/Modules/Ai/Models/AiUsageDaily.php', 'app/Modules/Ai/Services/AiUsageService.php']);
});

it('keeps the budget path free of raw SQL and of floats for money', function () {
    $files = array_map(
        fn (string $f) => Scanner::root().'/app/Modules/Ai/Services/'.$f,
        ['BudgetGuard.php', 'BudgetedAiProvider.php', 'AiUsageService.php', 'AiBudgetConfig.php', 'AiCostCalculator.php'],
    );

    expect(Scanner::violations($files, [
        '/\b(whereRaw|selectRaw|orderByRaw|DB::(raw|statement|select|unprepared))\b/',
        '/\(float\)\s*\$(cost|usd|total|used|cap|price)/i',
    ]))->toBeEmpty();
});
