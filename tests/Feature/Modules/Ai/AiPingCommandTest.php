<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| P7-08 — hm:ai-ping goes through the REAL budgeted provider stack (container -> BudgetedAiProvider -> GeminiDriver) with Http::fake():
| nothing leaves the machine. It must work while AI_ENABLED=false, never print the key, and report latency, tokens and cost.
*/

const PING_KEY = 'PING-KEY-MUST-NEVER-APPEAR';

function pingGeminiOk(string $text = 'ok'): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 2]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config([
        'ai.enabled' => false,   // a ping is how you verify BEFORE switching on
        'ai.provider' => 'gemini', 'ai.model' => 'test-model', 'ai.providers.gemini.api_key' => PING_KEY, 'ai.providers.gemini.base_url' => 'https://gemini.test/v1beta',
        'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '0.30', 'output' => '2.50']]],
        'ai.budget.monthly_usd' => '5', 'ai.budget.reasoning_margin_tokens' => 0, 'ai.max_retries' => 1, 'ai.budget.allow_unpriced' => false,
    ]);
});

it('succeeds, reporting provider, model, latency, tokens and cost — works with AI_ENABLED=false — and records the spend', function () {
    Http::fake(['gemini.test/*' => Http::response(pingGeminiOk())]);

    expect(Artisan::call('hm:ai-ping'))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('PING OK')->toContain('gemini/test-model')->toMatch('/\d+ ms/')->toContain('tokens in/out: 12/2')->toContain('cost: 0.00001 USD')->toContain('answer: ok')
        ->not->toContain(PING_KEY)
        ->and(DB::table('ai_usage_daily')->value('requests'))->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->hasHeader('x-goog-api-key', PING_KEY) && json_decode($r->body(), true)['generationConfig']['maxOutputTokens'] === 64);
});

it('does not even try the network when the setup is incomplete, and says what to fix', function (array $config, string $expected) {
    config($config);
    Http::fake();

    expect(Artisan::call('hm:ai-ping'))->toBe(1)->and(Artisan::output())->toContain($expected)->not->toContain(PING_KEY);
    Http::assertNothingSent();
})->with([
    'no key' => [['ai.providers.gemini.api_key' => ''], 'Not ready (missing_api_key)'],
    'no model' => [['ai.model' => ''], 'Not ready (missing_model)'],
    'unpriced model' => [['ai.pricing.models' => []], 'Not ready (unpriced_model)'],
    'unknown provider' => [['ai.provider' => 'skynet'], 'Not ready (unknown_provider)'],
]);

it('maps each failure to a plain cause and never prints the key', function (Closure $http, string $expected) {
    Http::fake(['gemini.test/*' => $http]);

    expect(Artisan::call('hm:ai-ping'))->toBe(1);
    $out = Artisan::output();

    expect($out)->toContain('PING FAILED')->toContain($expected)->not->toContain(PING_KEY);
})->with([
    'wrong key' => [fn () => Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key '.PING_KEY.' invalid']], 403), 'rejected the API key'],
    'rate limited' => [fn () => Http::response('', 429), 'rate-limited'],
    'unknown model (404)' => [fn () => Http::response(['error' => ['status' => 'NOT_FOUND']], 404), 'HTTP 404'],
    'network down' => [fn () => throw new ConnectionException('cURL error 6: could not resolve https://gemini.test/?key='.PING_KEY), 'Could not reach the provider'],
    'server error' => [fn () => Http::response('', 503), 'HTTP 503'],
    'the answer was cut off' => [fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'o']]], 'finishReason' => 'MAX_TOKENS']]]), 'unusable'],
]);

it('is refused by the budget like any other call', function () {
    config(['ai.budget.monthly_usd' => '0.0000001']);
    Http::fake();

    expect(Artisan::call('hm:ai-ping'))->toBe(1)->and(Artisan::output())->toContain('monthly budget');
    Http::assertNothingSent();
});

it('takes --max-tokens for reasoning models that need headroom', function () {
    Http::fake(['gemini.test/*' => Http::response(pingGeminiOk())]);

    Artisan::call('hm:ai-ping', ['--max-tokens' => 256]);

    Http::assertSent(fn ($r) => json_decode($r->body(), true)['generationConfig']['maxOutputTokens'] === 256);
});
