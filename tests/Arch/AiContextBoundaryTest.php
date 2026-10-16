<?php

declare(strict_types=1);

use App\Modules\Ai\Services\AiReadonlyRoleService;
use Tests\Arch\Scanner;

/*
| P7-06: the ContextBuilder is server-side assembly — no LLM, no network, no budget, no raw SQL. It reaches the database only
| through AiReadonlyDb and the tools; the final JSON must pass the PII guard.
*/

function contextFiles(): array
{
    return Scanner::phpFiles(['app/Modules/Ai/Context']);
}

it('has no HTTP, AI provider, budget or driver in the context code', function () {
    expect(contextFiles())->not->toBeEmpty()
        ->and(Scanner::violations(contextFiles(), [
            '/\bHttp::/', '/Illuminate\\\\Http\\\\Client/', '/\bAiProvider\b/', '/\bBudget\w*/', '/\bAiGateway\b/', '/\b(Gemini|Anthropic|OpenAi)Driver\b/', '/\bcurl_/',
        ]))->toBeEmpty();
});

it('uses no raw SQL, no DB facade, no model and no connection name in the context code', function () {
    expect(Scanner::violations(contextFiles(), [
        '/\b(select|having|order|where|groupBy|from|join)Raw\(/', '/\bDB::/', '/\\\\Models\\\\/', '/::query\(/', '/ai_readonly/', '/->connection\(\s*[\'"]/', '/->statement\(|->unprepared\(/',
    ]))->toBeEmpty();
});

it('reads only allowed tables, only through AiReadonlyDb', function () {
    $allowed = AiReadonlyRoleService::ALLOWED_TABLES;
    $used = [];

    foreach (contextFiles() as $file) {
        preg_match_all('/->table\(\s*[\'"](\w+)[\'"]/', Scanner::phpCode($file), $m);
        $used = [...$used, ...$m[1]];
    }

    expect(array_values(array_diff(array_unique($used), $allowed)))->toBe([])->and($used)->not->toBeEmpty();
});

it('ends every build in the PII guard and never embeds a clock reading in the payload', function () {
    $builder = Scanner::phpCode(Scanner::root().'/app/Modules/Ai/Context/ContextBuilder.php');

    expect(strpos($builder, '->assertClean('))->toBeInt()->and(strpos($builder, 'new BuiltContext('))->toBeGreaterThan(strpos($builder, '->assertClean('))
        ->and(Scanner::violations([Scanner::root().'/app/Modules/Ai/Context/ContextBuilder.php'], ['/\bnow\(/', '/CarbonImmutable::now|Carbon::now|\btime\(\)|microtime|hrtime|\brand\(|mt_rand|random_int|uniqid/']))->toBeEmpty();
});
