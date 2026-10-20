<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/*
| P7-05: the AnomalyDetector is pure statistics. No network, no LLM, no budget, no raw SQL, no model, no clock in the engine.
*/

function anomalyFiles(): array
{
    return Scanner::phpFiles(['app/Modules/Ai/Anomaly']);
}

it('has no HTTP, no AI provider and no budget path in the anomaly code', function () {
    expect(anomalyFiles())->not->toBeEmpty()
        ->and(Scanner::violations(anomalyFiles(), [
            '/\bHttp::/', '/Illuminate\\\\Http\\\\Client/', '/\bAiProvider\b/', '/\bBudget\w*/', '/\bAiGateway\b/', '/\b(Gemini|Anthropic|OpenAi)Driver\b/', '/\bcurl_/', '/\bfile_get_contents\(\s*[\'"]https?/',
        ]))->toBeEmpty();
});

it('uses no raw SQL, no DB facade, no model and no direct connection name in the anomaly code', function () {
    expect(Scanner::violations(anomalyFiles(), [
        '/\b(select|having|order|where|groupBy|from|join)Raw\(/', '/\bDB::/', '/\\\\Models\\\\/', '/::query\(/', '/ai_readonly/', '/->connection\(\s*[\'"]/', '/->statement\(|->unprepared\(/',
    ]))->toBeEmpty();
});

it('keeps the engine pure: no clock, no randomness, no I/O, no config() inside the maths', function () {
    $engine = [Scanner::root().'/app/Modules/Ai/Anomaly/AnomalyEngine.php', Scanner::root().'/app/Modules/Ai/Anomaly/RobustStats.php'];

    expect(Scanner::violations($engine, ['/\bnow\(/', '/CarbonImmutable::now|Carbon::now|\btime\(\)|microtime|\bdate\(\s*[\'"]/', '/\brand\(|mt_rand|random_int|shuffle\(/', '/\bconfig\(/', '/\bLog::|\bCache::|\bStorage::|file_put_contents|fopen/']))->toBeEmpty();
});

it('reads daily_metrics only through AiReadonlyDb', function () {
    $detector = Scanner::phpCode(Scanner::root().'/app/Modules/Ai/Anomaly/AnomalyDetector.php');

    expect($detector)->toContain('AiReadonlyDb')->and($detector)->toContain("table('daily_metrics')")
        ->and(preg_match_all("/->table\(\s*'(\w+)'/", $detector, $m) > 0 ? array_unique($m[1]) : [])->toBe(['daily_metrics']);
});
