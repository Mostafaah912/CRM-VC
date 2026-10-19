<?php

declare(strict_types=1);

use App\Modules\Ai\Drivers\GeminiDriver;
use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Dto\AiToolDefinition;
use App\Modules\Ai\Exceptions\AiAuthenticationException;
use App\Modules\Ai\Exceptions\AiConfigurationException;
use App\Modules\Ai\Exceptions\AiContentBlockedException;
use App\Modules\Ai\Exceptions\AiInvalidResponseException;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Exceptions\AiTimeoutException;
use App\Modules\Ai\Services\AiCostCalculator;
use App\Modules\Ai\Support\RawResponseCapture;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| P7-02 — GeminiDriver at the Laravel HTTP-client boundary. Http::preventStrayRequests() guarantees
| nothing here reaches the network (PRD §24). The response shapes below follow Google's documented
| format but are UNVERIFIED against the live API (sprint-7.md) — they must be re-checked by hand.
*/

const GEMINI_TEST_KEY = 'test-gemini-key-not-real';
const GEMINI_SECRET_PROMPT = 'SECRET-PROMPT-customer-Ali-09120000000';

/** @var list<array{level: string, message: string, context: array<string, mixed>}> */
$GLOBALS['ai_logs'] = [];

function gemini(int $maxRetries = 2): GeminiDriver
{
    return new GeminiDriver(GEMINI_TEST_KEY, 'https://gemini.test/v1beta', 'test-model-1.0', 30, $maxRetries, [2, 8, 30], new AiCostCalculator);
}

function aiReq(?array $schema = null, array $tools = [], array $messages = []): AiRequest
{
    return new AiRequest(GEMINI_SECRET_PROMPT, $messages ?: [AiMessage::user('hello')], $tools, $schema, 512, 0.1);
}

function geminiOk(array $parts, array $extra = []): array
{
    return array_merge([
        'candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 200, 'thoughtsTokenCount' => 50],
    ], $extra);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    $GLOBALS['ai_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e): void {
        $GLOBALS['ai_logs'][] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
    });
    config(['ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model-1.0' => ['input' => '0.30', 'output' => '2.50']]]]);
});

it('builds the generateContent request: url, key header, system, contents, generation config, schema and tools', function () {
    Http::fake(['gemini.test/*' => Http::response(geminiOk([['text' => '{"a":1}']]))]);

    gemini()->generate(aiReq(
        schema: ['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'integer']]],
        tools: [new AiToolDefinition('get_x', 'reads x'), new AiToolDefinition('get_y', 'reads y', ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]])],
    ));

    Http::assertSent(function (Request $r) {
        $b = $r->data();

        return $r->url() === 'https://gemini.test/v1beta/models/test-model-1.0:generateContent'
            && $r->hasHeader('x-goog-api-key', GEMINI_TEST_KEY)
            && ! str_contains($r->url(), GEMINI_TEST_KEY)
            && $b['systemInstruction']['parts'][0]['text'] === GEMINI_SECRET_PROMPT
            && $b['contents'] === [['role' => 'user', 'parts' => [['text' => 'hello']]]]
            && $b['generationConfig']['maxOutputTokens'] === 512
            && $b['generationConfig']['temperature'] === 0.1
            && $b['generationConfig']['responseMimeType'] === 'application/json'
            && ! array_key_exists('additionalProperties', $b['generationConfig']['responseSchema'])
            && $b['generationConfig']['responseSchema']['properties']['a']['type'] === 'integer'
            && $b['tools'][0]['functionDeclarations'][0]['name'] === 'get_x'
            && $b['tools'][0]['functionDeclarations'][1]['parameters']['properties']['n']['type'] === 'integer'
            && str_contains($r->body(), '"properties":{}');
    });
});

it('maps a tool-use conversation: assistant functionCall, then merged functionResponse parts', function () {
    Http::fake(['gemini.test/*' => Http::response(geminiOk([['text' => 'done']]))]);

    gemini()->generate(aiReq(messages: [
        AiMessage::user('q'),
        AiMessage::assistant('', [new AiToolCall('c1', 'get_x', ['k' => 1]), new AiToolCall('c2', 'get_y')]),
        AiMessage::tool('c1', 'get_x', '{"v":1}'),
        AiMessage::tool('c2', 'get_y', '[1,2]'),
    ]));

    Http::assertSent(function (Request $r) {
        $c = json_decode($r->body(), true)['contents'];

        return count($c) === 3
            && $c[1]['role'] === 'model' && $c[1]['parts'][0]['functionCall']['name'] === 'get_x' && $c[1]['parts'][0]['functionCall']['args'] === ['k' => 1]
            && $c[2]['role'] === 'user' && count($c[2]['parts']) === 2
            && $c[2]['parts'][0]['functionResponse'] === ['name' => 'get_x', 'response' => ['v' => 1]]
            && $c[2]['parts'][1]['functionResponse']['response'] === ['result' => [1, 2]];
    });
});

it('parses text, tokens (thinking counts as output), cost, model, provider and duration', function () {
    Http::fake(['gemini.test/*' => Http::response(geminiOk([['text' => 'سلام'], ['text' => 'hidden', 'thought' => true]]))]);

    $r = gemini()->generate(aiReq());

    // 1000 in * 0.30 + (200 + 50) out * 2.50, per million = 0.0003 + 0.000625
    expect($r->text)->toBe('سلام')
        ->and($r->json)->toBeNull()
        ->and($r->toolCalls)->toBe([])
        ->and($r->inputTokens)->toBe(1000)
        ->and($r->outputTokens)->toBe(250)
        ->and($r->reasoningTokens)->toBe(50)
        ->and($r->costUsd)->toBe('0.00093')
        ->and($r->model)->toBe('test-model-1.0')
        ->and($r->provider)->toBe('gemini')
        ->and($r->durationMs)->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('parses structured JSON output', function () {
    Http::fake(['gemini.test/*' => Http::response(geminiOk([['text' => '{"headline":"x","n":2}']]))]);

    $r = gemini()->generate(aiReq(schema: ['type' => 'object']));

    expect($r->json)->toBe(['headline' => 'x', 'n' => 2]);
});

it('parses function calls, with and without a provider id', function () {
    Http::fake(['gemini.test/*' => Http::response(geminiOk([
        ['functionCall' => ['name' => 'get_x', 'args' => ['k' => 1]]],
        ['functionCall' => ['id' => 'abc', 'name' => 'get_y']],
    ]))]);

    $r = gemini()->generate(aiReq(schema: ['type' => 'object']));

    expect($r->hasToolCalls())->toBeTrue()
        ->and($r->text)->toBeNull()
        ->and($r->json)->toBeNull()
        ->and($r->toolCalls)->toHaveCount(2)
        ->and($r->toolCalls[0]->name)->toBe('get_x')
        ->and($r->toolCalls[0]->arguments)->toBe(['k' => 1])
        ->and($r->toolCalls[0]->id)->toBe('get_x_0')
        ->and($r->toolCalls[1]->id)->toBe('abc')
        ->and($r->toolCalls[1]->arguments)->toBe([]);
});

it('reports zero cost and warns when the model has no price (never a guessed one)', function () {
    config(['ai.pricing.models' => []]);
    Http::fake(['gemini.test/*' => Http::response(geminiOk([['text' => 'x']]))]);

    $r = gemini()->generate(aiReq());

    expect($r->costUsd)->toBe('0.00000')
        ->and(collect($GLOBALS['ai_logs'])->where('level', 'warning')->count())->toBe(1);
});

dataset('gemini failures', [
    '401 → auth' => [401, AiAuthenticationException::class, false],
    '403 → auth' => [403, AiAuthenticationException::class, false],
    '429 → rate limit' => [429, AiRateLimitException::class, true],
    '500 → transient' => [500, AiProviderException::class, true],
    '503 → transient' => [503, AiProviderException::class, true],
    '400 → terminal' => [400, AiProviderException::class, false],
    '404 → terminal' => [404, AiProviderException::class, false],
]);

it('maps each HTTP failure to its domain exception', function (int $status, string $class, bool $retryable) {
    Http::fake(['gemini.test/*' => Http::response(['error' => ['code' => $status, 'status' => 'RESOURCE_EXHAUSTED', 'message' => GEMINI_SECRET_PROMPT]], $status)]);

    try {
        gemini(maxRetries: 0)->generate(aiReq());
        $this->fail('expected an exception');
    } catch (AiProviderException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e::class === AiProviderException::class || $e instanceof $class)->toBeTrue()
            ->and($e->status)->toBe($status)
            ->and($e->retryable)->toBe($retryable)
            ->and($e->provider)->toBe('gemini')
            ->and($e->getMessage())->toContain("HTTP {$status}")->toContain('RESOURCE_EXHAUSTED');
    }
})->with('gemini failures');

it('maps a connection failure / timeout to AiTimeoutException', function () {
    Http::fake(['gemini.test/*' => fn () => throw new ConnectionException('cURL error 28: https://gemini.test/v1beta/models/x?key=LEAK')]);

    try {
        gemini(maxRetries: 0)->generate(aiReq());
        $this->fail('expected an exception');
    } catch (AiTimeoutException $e) {
        expect($e->retryable)->toBeTrue()->and($e->status)->toBeNull()->and($e->getMessage())->not->toContain('LEAK')->not->toContain('curl');
    }
});

it('maps blocked prompts and blocked answers to AiContentBlockedException', function (array $payload) {
    Http::fake(['gemini.test/*' => Http::response($payload)]);

    gemini()->generate(aiReq());
})->with([
    'prompt blocked' => [['promptFeedback' => ['blockReason' => 'SAFETY']]],
    'answer SAFETY' => [['candidates' => [['finishReason' => 'SAFETY']]]],
    'answer PROHIBITED_CONTENT' => [['candidates' => [['finishReason' => 'PROHIBITED_CONTENT']]]],
])->throws(AiContentBlockedException::class);

it('maps unusable 2xx answers to AiInvalidResponseException', function (mixed $payload) {
    Http::fake(['gemini.test/*' => Http::response($payload)]);

    gemini()->generate(aiReq(schema: ['type' => 'object']));
})->with([
    'no candidates' => [['candidates' => []]],
    'empty parts' => [geminiOk([])],
    'cut off' => [['candidates' => [['content' => ['parts' => [['text' => '{"a"']]], 'finishReason' => 'MAX_TOKENS']]]],
    'schema asked, text is not JSON' => [geminiOk([['text' => 'not json']])],
    'schema asked, JSON is a list' => [geminiOk([['text' => '[1,2]']])],
    'not an object' => ['"just a string"'],
])->throws(AiInvalidResponseException::class);

it('retries only transient failures and stops at the configured ceiling', function () {
    Http::fake(['gemini.test/*' => Http::response('', 503)]);

    try {
        gemini(maxRetries: 2)->generate(aiReq());
        $this->fail('expected an exception');
    } catch (AiProviderException $e) {
        expect($e->status)->toBe(503);
    }

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(8)->seconds()]);
});

it('recovers when a retry succeeds', function () {
    Http::fake(['gemini.test/*' => Http::sequence()->push('', 429)->push(geminiOk([['text' => 'ok']]))]);

    expect(gemini()->generate(aiReq())->text)->toBe('ok');
    Http::assertSentCount(2);
});

it('never retries auth, block, invalid-request or invalid-response failures', function (int $status) {
    Http::fake(['gemini.test/*' => Http::response('', $status)]);

    expect(fn () => gemini(maxRetries: 5)->generate(aiReq()))->toThrow(AiProviderException::class);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([401, 403, 400, 404]);

it('does not retry a 2xx answer that is unusable', function () {
    Http::fake(['gemini.test/*' => Http::response(['candidates' => []])]);

    expect(fn () => gemini(maxRetries: 5)->generate(aiReq()))->toThrow(AiInvalidResponseException::class);
    Http::assertSentCount(1);
});

it('puts neither the API key, the prompt, nor customer data in any exception message or log line', function () {
    $scenarios = [
        fn () => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => GEMINI_SECRET_PROMPT.' '.GEMINI_TEST_KEY]], 400),
        fn () => Http::response('', 503),
        fn () => throw new ConnectionException('timeout calling https://gemini.test/?key='.GEMINI_TEST_KEY.' with '.GEMINI_SECRET_PROMPT),
        fn () => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY', 'blockReasonMessage' => GEMINI_SECRET_PROMPT]]),
        fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => GEMINI_SECRET_PROMPT]]], 'finishReason' => 'MAX_TOKENS']]]),
    ];
    $messages = [];

    foreach ($scenarios as $scenario) {
        Http::fake(['gemini.test/*' => $scenario]);

        try {
            gemini(maxRetries: 1)->generate(aiReq(schema: ['type' => 'object']));
        } catch (AiProviderException $e) {
            $messages[] = $e->getMessage();
        }
    }

    $everything = json_encode([$messages, $GLOBALS['ai_logs']], JSON_UNESCAPED_UNICODE);

    expect($messages)->toHaveCount(5)
        ->and($everything)->not->toContain(GEMINI_TEST_KEY)
        ->not->toContain('SECRET-PROMPT')
        ->not->toContain('09120000000')
        ->not->toContain('Ali');
});

it('refuses to be built without a key, base url or model, or with an unsafe model id', function (string $key, string $url, string $model) {
    new GeminiDriver($key, $url, $model, 30, 2, [2], new AiCostCalculator);
})->with([
    'no key' => ['', 'https://x.test', 'm'],
    'no url' => ['k', '', 'm'],
    'no model' => ['k', 'https://x.test', ''],
    'path-injecting model' => ['k', 'https://x.test', '../other:generate?x=1'],
])->throws(AiConfigurationException::class);

it('rejects an invalid request before any HTTP call', function () {
    new AiRequest('s', []);
})->throws(InvalidArgumentException::class);

/*
| P7-12 — billed output = max(candidates + thoughts, totalTokenCount − promptTokenCount): thinking is never under-counted, whether
| thoughtsTokenCount is reported separately or not. The same number is used on the success path and on the billed-failure path.
*/
dataset('usage shapes', [
    'thoughts reported separately' => [['promptTokenCount' => 100, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 20, 'totalTokenCount' => 150], 100, 50, 20],
    'thoughts NOT reported — recovered from the total' => [['promptTokenCount' => 100, 'candidatesTokenCount' => 30, 'totalTokenCount' => 180], 100, 80, 50],
    'no total at all — falls back to candidates + thoughts' => [['promptTokenCount' => 100, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 20], 100, 50, 20],
    'total is larger (tool-use prompt tokens) — the larger wins' => [['promptTokenCount' => 100, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 20, 'totalTokenCount' => 200], 100, 100, 70],
    'total smaller than the parts — the parts win' => [['promptTokenCount' => 100, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 20, 'totalTokenCount' => 120], 100, 50, 20],
]);

it('reads billed output from totalTokenCount − promptTokenCount on a successful answer', function (array $usage, int $in, int $out, int $reasoning) {
    Http::fake(['*' => Http::response(geminiOk([['text' => 'hi']], ['usageMetadata' => $usage]), 200)]);

    $r = gemini()->generate(aiReq());

    expect([$r->inputTokens, $r->outputTokens, $r->reasoningTokens])->toBe([$in, $out, $reasoning]);
})->with('usage shapes');

it('uses the same billed output when the answer is cut off (the failure path)', function (array $usage, int $in, int $out, int $reasoning) {
    Http::fake(['*' => Http::response(geminiOk([['text' => '{"a":']], ['usageMetadata' => $usage, 'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"a":']]]]]]), 200)]);

    try {
        gemini()->generate(aiReq(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]));
        $this->fail('expected a cut-off');
    } catch (AiInvalidResponseException $e) {
        expect([$e->billed->inputTokens, $e->billed->outputTokens, $e->billed->reasoningTokens])->toBe([$in, $out, $reasoning]);
    }
})->with('usage shapes');

it('logs the usageMetadata numbers (and only numbers) once per answer', function () {
    Http::fake(['*' => Http::response(geminiOk([['text' => 'hi']], ['usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 3, 'totalTokenCount' => 10, 'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 7]]]]), 200)]);

    gemini()->generate(aiReq());
    $logs = array_values(array_filter($GLOBALS['ai_logs'], fn ($l) => $l['message'] === 'Gemini usageMetadata'));

    expect($logs)->toHaveCount(1)->and($logs[0]['context'])->toBe(['promptTokenCount' => 7, 'candidatesTokenCount' => 3, 'totalTokenCount' => 10]);
});

/*
| thinkingLevel (documented REST field for gemini-3.8-flash: generationConfig.thinkingConfig.thinkingLevel, lowercase low|medium|high)
| and the --debug-raw capture of the raw provider answer.
*/
it('sends generationConfig.thinkingConfig.thinkingLevel from config (default low)', function () {
    Http::fake(['*' => Http::response(geminiOk([['text' => 'hi']]), 200)]);
    gemini()->generate(aiReq());

    Http::assertSent(fn ($r) => ($r->data()['generationConfig']['thinkingConfig'] ?? null) === ['thinkingLevel' => 'low']);
    expect((require base_path('config/ai.php'))['gemini']['thinking_level'])->toBe('low');
});

it('sends the configured level, and sends nothing for an empty or unknown one (never `minimal`, which the model rejects)', function (?string $level, ?array $expected) {
    config(['ai.gemini.thinking_level' => $level]);
    Http::fake(['*' => Http::response(geminiOk([['text' => 'hi']]), 200)]);
    gemini()->generate(aiReq());

    Http::assertSent(fn ($r) => ($r->data()['generationConfig']['thinkingConfig'] ?? null) === $expected);
})->with([['high', ['thinkingLevel' => 'high']], ['medium', ['thinkingLevel' => 'medium']], ['', null], [null, null], ['minimal', null], ['LOW', null]]);

it('keeps the raw answer only when --debug-raw armed the capture — also for a cut-off answer — and nothing otherwise', function () {
    $cut = geminiOk([['text' => '{"a":']], ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"a":']]]]]]);
    Http::fake(['*' => Http::response($cut, 200)]);
    $capture = new RawResponseCapture;
    app()->instance(RawResponseCapture::class, $capture);

    try {
        gemini()->generate(aiReq(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]));
    } catch (AiInvalidResponseException) {
    }

    expect($capture->files())->toBe([]);   // not armed

    $capture->arm();

    try {
        gemini()->generate(aiReq(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]));
    } catch (AiInvalidResponseException) {
    }

    expect($capture->files())->toHaveCount(1)->and(str_contains($capture->files()[0], 'storage/app/tmp/ai-raw-gemini-'))->toBeTrue()
        ->and(json_decode((string) file_get_contents($capture->files()[0]), true))->toBe($cut)
        ->and(file_get_contents($capture->files()[0]))->not->toContain(GEMINI_SECRET_PROMPT)->not->toContain(GEMINI_TEST_KEY);

    array_map('unlink', $capture->files());
});
