<?php

declare(strict_types=1);

use App\Modules\Ai\Analyst\AnalystRequestBuilder;
use App\Modules\Ai\Analyst\InsightGenerator;
use App\Modules\Ai\Analyst\OutputValidator;
use App\Modules\Ai\Analyst\PromptRepository;
use App\Modules\Ai\Analyst\ResponseCache;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Exceptions\AiResponseInvalidException;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AiUsageService;
use App\Modules\Ai\Services\BudgetedAiProvider;
use App\Modules\Ai\Services\BudgetGuard;
use App\Modules\Ai\Services\FakeAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AnalystSamples;

/*
| P7-07 — validation + one corrective retry + cache, driven by FakeAiProvider (no network). A second provider call is a REAL second call:
| the budget tests wrap the fake in BudgetedAiProvider to prove the retry goes through BudgetGuard and a cache hit never does.
*/

const BAD_TEXT = 'CANARY-BAD-OUTPUT {not json';

function insightGenerator(FakeAiProvider|AiProvider $provider): InsightGenerator
{
    return new InsightGenerator(
        $provider,
        app(AnalystRequestBuilder::class),
        app(OutputValidator::class),
        app(ResponseCache::class),
        app(PromptRepository::class),
    );
}

function okResponse(array $answer, int $in = 1000, int $out = 400, string $cost = '0.00100'): AiResponse
{
    return FakeAiProvider::response(json: $answer, inputTokens: $in, outputTokens: $out, costUsd: $cost, model: 'm1');
}

function storeInsightRow(string $key, array $payload, ?CarbonImmutable $at = null): void
{
    DB::table('ai_insights')->insert(['type' => 'daily_brief', 'payload' => json_encode($payload), 'provider' => 'gemini', 'model' => 'm1', 'prompt_version' => 'analyst.daily.v1', 'cache_key' => $key, 'created_at' => $at ?? now()]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    config(['ai.model' => 'test-model']);
});

it('accepts a valid answer on the first call and reports tokens, cost and model', function () {
    $fake = (new FakeAiProvider)->queue(okResponse(AnalystSamples::answer()));

    $g = insightGenerator($fake)->generate(AiInsightType::DailyBrief, AnalystSamples::context());

    expect($g->source)->toBe('model')->and($g->attempts)->toBe(1)->and($g->payload)->toBe(AnalystSamples::answer())->and($g->promptVersion)->toBe('analyst.daily.v3')
        ->and($g->type)->toBe('daily_brief')->and($g->inputTokens)->toBe(1000)->and($g->outputTokens)->toBe(400)->and($g->costUsd)->toBe('0.00100')->and($g->model)->toBe('m1')
        ->and($g->redactions)->toBe(0)->and($g->responses)->toHaveCount(1)->and($fake->callCount())->toBe(1)
        ->and($fake->calls()[0]->responseSchema)->not->toBeNull()->and($fake->calls()[0]->tools)->toBe([]);
});

it('retries ONCE with the validator\'s findings (and not the model\'s text) when the first answer is broken, then succeeds', function (AiResponse $broken) {
    $fake = (new FakeAiProvider)->queue($broken)->queue(okResponse(AnalystSamples::answer(), 1100, 500, '0.00200'));

    $g = insightGenerator($fake)->generate(AiInsightType::DailyBrief, AnalystSamples::context());
    [$first, $second] = $fake->calls();

    expect($g->attempts)->toBe(2)->and($fake->callCount())->toBe(2)->and($g->payload)->toBe(AnalystSamples::answer())
        ->and($g->inputTokens)->toBe(1000 + 1100)->and($g->outputTokens)->toBe(100 + 500)->and($g->costUsd)->toBe('0.00200')->and($g->responses)->toHaveCount(2)
        ->and($first->messages)->toHaveCount(1)
        ->and($second->messages)->toHaveCount(3)
        ->and(array_map(fn ($m) => $m->role->value, $second->messages))->toBe(['user', 'assistant', 'user'])
        ->and($second->messages[0])->toEqual($first->messages[0])           // same data, same instructions
        ->and($second->messages[2]->content)->toContain('Your previous answer was rejected')->toContain('- ')
        ->and(json_encode($second->messages, JSON_UNESCAPED_UNICODE))->not->toContain('CANARY-BAD-OUTPUT')
        ->and($second->systemPrompt)->toBe($first->systemPrompt)->and($second->responseSchema)->toBe($first->responseSchema);
})->with([
    'text that is not JSON' => [fn () => FakeAiProvider::response(text: BAD_TEXT, inputTokens: 1000, outputTokens: 100)],
    'a JSON list' => [fn () => FakeAiProvider::response(text: '[1,2]', inputTokens: 1000, outputTokens: 100)],
    'valid JSON, wrong shape' => [fn () => FakeAiProvider::response(json: ['headline' => 'x', 'canary' => 'CANARY-BAD-OUTPUT'], inputTokens: 1000, outputTokens: 100)],
    'a tool call instead of an answer' => [fn () => FakeAiProvider::response(toolCalls: [new AiToolCall('c', 'get_x', [])], inputTokens: 1000, outputTokens: 100)],
]);

it('retries when a metric_ref is not in the context (grounding), naming the path to fix', function () {
    $bad = AnalystSamples::answer();
    $bad['key_metrics'][0]['metric_refs'] = ['kpis.windows.0.result.current.profit'];
    $fake = (new FakeAiProvider)->queue(okResponse($bad))->queue(okResponse(AnalystSamples::answer()));

    $g = insightGenerator($fake)->generate(AiInsightType::DailyBrief, AnalystSamples::context());

    expect($g->attempts)->toBe(2)->and($fake->calls()[1]->messages[2]->content)->toContain('key_metrics.0.metric_refs.0: unknown reference');
});

it('fails with a controlled exception after exactly TWO provider calls when both answers are invalid — and carries no model text', function () {
    $fake = (new FakeAiProvider)->queue(FakeAiProvider::response(text: BAD_TEXT))->queue(FakeAiProvider::response(json: ['headline' => 'CANARY-BAD-OUTPUT']));

    try {
        insightGenerator($fake)->generate(AiInsightType::DailyBrief, AnalystSamples::context());
        $this->fail('expected AiResponseInvalidException');
    } catch (AiResponseInvalidException $e) {
        expect($e->attempts)->toBe(2)->and($e->errors)->not->toBeEmpty()->and($e->getMessage())->not->toContain('CANARY')->and(implode(' ', $e->errors))->not->toContain('CANARY')
            ->and($fake->callCount())->toBe(2)->and($fake->remaining())->toBe(0);
    }
});

it('never makes a third call, however the second answer fails', function () {
    $fake = (new FakeAiProvider)->queue(FakeAiProvider::response(text: 'x'))->queue(FakeAiProvider::response(text: 'y'))->queue(okResponse(AnalystSamples::answer()));

    expect(fn () => insightGenerator($fake)->generate(AiInsightType::DailyBrief, AnalystSamples::context()))->toThrow(AiResponseInvalidException::class)->and($fake->callCount())->toBe(2)->and($fake->remaining())->toBe(1);
});

it('lets a provider error (rate limit, budget ...) through untouched instead of retrying it as an invalid answer', function () {
    $fake = (new FakeAiProvider)->queue(new AiRateLimitException('limited', 'fake', 429, true));

    expect(fn () => insightGenerator($fake)->generate(AiInsightType::DailyBrief, AnalystSamples::context()))->toThrow(AiRateLimitException::class)->and($fake->callCount())->toBe(1);
});

it('normalises Persian digits and redacts customer-looking text in the model\'s answer, and says how many', function () {
    $answer = AnalystSamples::answer();
    $answer['headline'] = 'فروش ۴۴ سفارش';
    $answer['summary'] = 'با 09121234567 تماس بگیر';

    $g = insightGenerator((new FakeAiProvider)->queue(okResponse($answer)))->generate(AiInsightType::DailyBrief, AnalystSamples::context());

    expect($g->payload['headline'])->toBe('فروش 44 سفارش')->and($g->payload['summary'])->toBe('[redacted]')->and($g->redactions)->toBe(1);
});

describe('cache', function () {
    it('builds a deterministic key of at most 120 characters, different for every ingredient', function () {
        $cache = app(ResponseCache::class);
        $json = AnalystSamples::context()->json;
        $base = $cache->key('daily_brief', 'analyst.daily.v1', 'gemini-x', $json);

        expect($cache->key('daily_brief', 'analyst.daily.v1', 'gemini-x', $json))->toBe($base)
            ->and(strlen($base))->toBeLessThanOrEqual(120)->and(strlen($cache->key('weekly_review', 'analyst.weekly.v1', str_repeat('m', 80), $json.'x')))->toBeLessThanOrEqual(120)
            ->and($cache->key('weekly_review', 'analyst.daily.v1', 'gemini-x', $json))->not->toBe($base)
            ->and($cache->key('daily_brief', 'analyst.daily.v3', 'gemini-x', $json))->not->toBe($base)
            ->and($cache->key('daily_brief', 'analyst.daily.v1', 'gemini-y', $json))->not->toBe($base)
            ->and($cache->key('daily_brief', 'analyst.daily.v1', 'gemini-x', str_replace('2026-10-13', '2026-10-14', $json)))->not->toBe($base);
    });

    it('a fresh hit returns the stored payload with ZERO provider calls', function () {
        $context = AnalystSamples::context();
        $key = app(ResponseCache::class)->key('daily_brief', 'analyst.daily.v3', 'test-model', $context->json);
        storeInsightRow($key, AnalystSamples::answer());
        $fake = new FakeAiProvider;

        $g = insightGenerator($fake)->generate(AiInsightType::DailyBrief, $context);

        expect($g->source)->toBe('cache')->and($g->payload)->toEqual(AnalystSamples::answer())->and($g->cacheKey)->toBe($key)->and($g->attempts)->toBe(0)->and($g->responses)->toBe([])
            ->and($g->inputTokens)->toBe(0)->and($g->outputTokens)->toBe(0)->and($g->costUsd)->toBe('0.00000')->and($g->model)->toBeNull()->and($fake->callCount())->toBe(0);
    });

    it('misses when the context, model or prompt version differs, and when the row is older than the TTL', function () {
        $context = AnalystSamples::context();
        $cache = app(ResponseCache::class);
        $key = $cache->key('daily_brief', 'analyst.daily.v3', 'test-model', $context->json);
        storeInsightRow($key, AnalystSamples::answer(), CarbonImmutable::now()->subMinutes(1441));   // default TTL is 1440 minutes

        expect($cache->lookup($key))->toBeNull();

        config(['ai.cache.ttl_minutes' => 2000]);
        expect($cache->lookup($key))->toEqual(AnalystSamples::answer());

        config(['ai.model' => 'another-model']);
        $fake = (new FakeAiProvider)->queue(okResponse(AnalystSamples::answer()));
        $g = insightGenerator($fake)->generate(AiInsightType::DailyBrief, $context);

        expect($g->source)->toBe('model')->and($fake->callCount())->toBe(1)->and($g->cacheKey)->not->toBe($key);

        $payload = AnalystSamples::contextPayload();
        $payload['data_quality']['as_of']['iso'] = '2026-10-14';   // tomorrow's data
        config(['ai.model' => 'test-model']);
        $fresh = (new FakeAiProvider)->queue(okResponse(AnalystSamples::answer()));

        expect(insightGenerator($fresh)->generate(AiInsightType::DailyBrief, AnalystSamples::context($payload))->source)->toBe('model');
    });

    it('`force` bypasses a fresh hit', function () {
        $context = AnalystSamples::context();
        storeInsightRow(app(ResponseCache::class)->key('daily_brief', 'analyst.daily.v3', 'test-model', $context->json), ['stale' => true]);
        $fake = (new FakeAiProvider)->queue(okResponse(AnalystSamples::answer()));

        $g = insightGenerator($fake)->generate(AiInsightType::DailyBrief, $context, force: true);

        expect($g->source)->toBe('model')->and($g->payload)->toBe(AnalystSamples::answer())->and($fake->callCount())->toBe(1);
    });
});

describe('budget', function () {
    beforeEach(function () {
        config([
            'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '1', 'output' => '1']]],
            'ai.budget.monthly_usd' => '100',
            'ai.budget.allow_unpriced' => false,
            'ai.budget.reasoning_margin_tokens' => 0,
        ]);
    });

    function budgetedWrapper(FakeAiProvider $fake): BudgetedAiProvider
    {
        return new BudgetedAiProvider($fake, app(BudgetGuard::class), app(AiUsageService::class));
    }

    it('the corrective retry is a second REAL call: both pass BudgetGuard and both are recorded', function () {
        $fake = (new FakeAiProvider)->queue(FakeAiProvider::response(text: 'nope', inputTokens: 100, outputTokens: 10, costUsd: '0.00010'))
            ->queue(okResponse(AnalystSamples::answer(), 200, 20, '0.00020'));

        $g = insightGenerator(budgetedWrapper($fake))->generate(AiInsightType::DailyBrief, AnalystSamples::context());
        $row = DB::table('ai_usage_daily')->first();

        expect($g->attempts)->toBe(2)->and($row->requests)->toBe(2)->and($row->input_tokens)->toBe(300)->and($row->output_tokens)->toBe(30)->and($row->cost_usd)->toBe('0.00030');
    });

    it('a cache hit spends nothing: no provider call, no usage row, no reservation', function () {
        $context = AnalystSamples::context();
        storeInsightRow(app(ResponseCache::class)->key('daily_brief', 'analyst.daily.v3', 'test-model', $context->json), AnalystSamples::answer());
        $fake = new FakeAiProvider;

        $g = insightGenerator(budgetedWrapper($fake))->generate(AiInsightType::DailyBrief, $context);

        expect($g->source)->toBe('cache')->and($fake->callCount())->toBe(0)->and(DB::table('ai_usage_daily')->count())->toBe(0)->and(Cache::get('ai:budget:reservations'))->toBeNull();
    });

    it('a budget refusal reaches the caller untouched and the provider is never called', function () {
        config(['ai.budget.monthly_usd' => '0.00001']);   // far below one request's worst case (~23k tokens at $1/M), so the call is refused up front
        $fake = (new FakeAiProvider)->queue(okResponse(AnalystSamples::answer()));

        expect(fn () => insightGenerator(budgetedWrapper($fake))->generate(AiInsightType::DailyBrief, AnalystSamples::context()))->toThrow(BudgetExceededException::class)
            ->and($fake->callCount())->toBe(0);
    });
});
