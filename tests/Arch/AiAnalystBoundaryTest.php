<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P7-07: prompt building, validation and caching are pure Ai-module logic. The generator talks to the model ONLY through the AiProvider
| interface (so BudgetGuard always sits in between); nothing here knows a driver, the factory, the HTTP client, a key or a model id.
*/

function analystFiles(): array
{
    return Scanner::phpFiles(['app/Modules/Ai/Analyst']);
}

it('reaches the model only through the AiProvider interface: no driver, factory, HTTP, key or model id', function () {
    expect(analystFiles())->not->toBeEmpty()
        ->and(Scanner::violations(analystFiles(), [
            '/\b(Gemini|Anthropic|OpenAi)Driver\b/', '/\bAiProviderFactory\b/', '/\bHttp::/', '/Illuminate\\\\Http\\\\Client/', '/\bcurl_/', '/\bBudgetGuard\b/', '/api_key/i',
            '/[\'"](gemini|claude|gpt|o[134])-[\w.-]*[\'"]/i', '/config\(\s*[\'"]ai\.model[\'"]\s*\)\s*===?\s*[\'"]/',
        ]))->toBeEmpty();
});

it('uses no raw SQL and no DB facade; only the ResponseCache reads ai_insights, through the model', function () {
    expect(Scanner::violations(analystFiles(), ['/\b(select|having|order|where|groupBy|from|join)Raw\(/', '/\bDB::/', '/->statement\(|->unprepared\(/']))->toBeEmpty();

    $users = [];

    foreach (analystFiles() as $file) {
        if (preg_match('/\bAiInsight\b/', Scanner::phpCode($file)) === 1) {
            $users[] = Scanner::relative($file);
        }
    }

    expect($users)->toBe(['app/Modules/Ai/Analyst/ResponseCache.php']);
});

it('keeps the preview command free of any provider, key or network', function () {
    $command = [Scanner::root().'/app/Console/Commands/AiPreviewCommand.php'];

    expect(Scanner::violations($command, ['/\bAiProvider\b/', '/\bBudget\w*/', '/\b(Gemini|Anthropic|OpenAi)Driver\b/', '/\bAiProviderFactory\b/', '/\bInsightGenerator\b/', '/\bHttp::/', '/api_key/i']))->toBeEmpty();
});

it('never lets the prompts or their lock hold a key or a secret', function () {
    $files = array_merge(glob(Scanner::root().'/resources/prompts/*.md') ?: [], [Scanner::root().'/tests/fixtures/ai_prompt_lock.json']);

    expect($files)->not->toBeEmpty()->and(Scanner::violations($files, ['/\bAIza[0-9A-Za-z_-]{35}\b/', '/\bsk-[A-Za-z0-9]{20,}/', '/\bck_[0-9a-f]{40}\b/', '/-----BEGIN [A-Z ]*PRIVATE KEY-----/', '/\b09\d{9}\b/', '/[\w.+-]+@[\w-]+\.[\w.]+/'], stripPhpComments: false))->toBeEmpty();
});

it('passes every provider call through the AiProvider interface only (the generator holds the interface, not a concrete class)', function () {
    $code = Scanner::phpCode(Scanner::root().'/app/Modules/Ai/Analyst/InsightGenerator.php');

    expect($code)->toContain('private readonly AiProvider $provider')->and(substr_count($code, '->generate('))->toBe(1);
});
