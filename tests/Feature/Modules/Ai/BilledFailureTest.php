<?php

declare(strict_types=1);

use App\Modules\Ai\Drivers\AnthropicDriver;
use App\Modules\Ai\Drivers\GeminiDriver;
use App\Modules\Ai\Drivers\OpenAiDriver;
use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Exceptions\AiContentBlockedException;
use App\Modules\Ai\Exceptions\AiInvalidResponseException;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Services\AiCostCalculator;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AiUsageService;
use App\Modules\Ai\Services\BudgetedAiProvider;
use App\Modules\Ai\Services\BudgetGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| Budget gap closed: a provider that ANSWERED with an unusable answer (cut off at the token limit, blocked, malformed) still charged the
| tokens. Those exceptions carry the real usage (`billed`) and BudgetedAiProvider records it in ai_usage_daily (so the month ledger moves),
| and the reservation is freed on EVERY path. Failures with no usage (timeout, 4xx/5xx) record nothing — unchanged.
| Real drivers over Http::fake: nothing here touches the network.
*/

function billedDrivers(): array
{
    $costs = new AiCostCalculator;

    return [
        'gemini' => fn () => new GeminiDriver('k', 'https://g.test/v1', 'test-model', 30, 0, [1], $costs),
        'anthropic' => fn () => new AnthropicDriver('k', 'https://a.test/v1', 'test-model', 30, 0, [1], $costs),
        'openai' => fn () => new OpenAiDriver('k', 'https://o.test/v1', 'test-model', 30, 0, [1], $costs),
    ];
}

/** The provider's wire format for one answer, with usage in = 100, out = 30 (+ 20 reasoning where the provider reports it). */
function wire(string $driver, string $kind): array
{
    return match ($driver) {
        'gemini' => ['candidates' => [['finishReason' => match ($kind) {
            'cut' => 'MAX_TOKENS', 'blocked' => 'SAFETY', default => 'STOP'
        }, 'content' => ['parts' => [['text' => $kind === 'malformed' ? 'not json' : '{"a":']]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 20]],
        'anthropic' => ['stop_reason' => match ($kind) {
            'cut' => 'max_tokens', 'blocked' => 'refusal', default => 'end_turn'
        }, 'content' => [['type' => 'text', 'text' => $kind === 'malformed' ? 'not json' : '{"a":']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50]],
        'openai' => ['choices' => [['finish_reason' => match ($kind) {
            'cut' => 'length', 'blocked' => 'content_filter', default => 'stop'
        }, 'message' => ['content' => $kind === 'malformed' ? 'not json' : '{"a":']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'completion_tokens_details' => ['reasoning_tokens' => 20]]],
    };
}

function billedRequest(): AiRequest
{
    return new AiRequest('sys', [AiMessage::user('q')], [], ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 256, 0.2);
}

function budgetedOver(AiProvider $driver): BudgetedAiProvider
{
    return new BudgetedAiProvider($driver, app(BudgetGuard::class), app(AiUsageService::class));
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Cache::flush();
    config([
        'ai.model' => 'test-model',
        'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '1', 'output' => '2']]],
        'ai.budget.monthly_usd' => '40', 'ai.budget.monthly_tokens' => null, 'ai.budget.allow_unpriced' => false, 'ai.budget.reasoning_margin_tokens' => 0,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Tehran'));
});

dataset('billed failures', function () {
    foreach (['gemini', 'anthropic', 'openai'] as $driver) {
        foreach (['cut' => AiInvalidResponseException::class, 'blocked' => AiContentBlockedException::class, 'malformed' => AiInvalidResponseException::class] as $kind => $exception) {
            yield "{$driver} / {$kind}" => [$driver, $kind, $exception];
        }
    }
});

it('records the tokens and cost of an answer that was billed but unusable, and frees the reservation', function (string $driver, string $kind, string $exception) {
    Http::fake(['*' => Http::response(wire($driver, $kind), 200)]);
    $provider = budgetedOver((billedDrivers()[$driver])());
    $in = 100;
    $out = $driver === 'gemini' ? 50 : 50;   // gemini: 30 visible + 20 thinking; the others report 50 output
    $expected = (new AiCostCalculator)->cost($driver, 'test-model', $in, $out);

    try {
        $provider->generate(billedRequest());
        $this->fail('expected an exception');
    } catch (AiProviderException $e) {
        expect($e)->toBeInstanceOf($exception)->and($e->billed)->not->toBeNull()->and($e->billed->inputTokens)->toBe($in)->and($e->billed->outputTokens)->toBe($out)
            ->and($e->billed->text)->toBeNull()->and($e->getMessage())->not->toContain('not json');
    }

    $row = DB::table('ai_usage_daily')->first();

    expect(DB::table('ai_usage_daily')->count())->toBe(1)
        ->and([(int) $row->requests, (int) $row->input_tokens, (int) $row->output_tokens, $row->cost_usd])->toBe([1, $in, $out, $expected])
        ->and((float) $expected)->toBeGreaterThan(0.0)
        ->and(Cache::get('ai:budget:reservations', []))->toBe([]);
})->with('billed failures');

it('splits Gemini thinking tokens out of the billed usage: reasoning is inside the output count', function () {
    Http::fake(['*' => Http::response(wire('gemini', 'cut'), 200)]);

    try {
        budgetedOver((billedDrivers()['gemini'])())->generate(billedRequest());
    } catch (AiProviderException $e) {
        expect($e->billed->reasoningTokens)->toBe(20)->and($e->billed->outputTokens)->toBe(50);
    }
});

it('records nothing for failures that carry no usage (timeout before an answer, 4xx/5xx) and still frees the reservation', function (int $status, string $driver) {
    Http::fake(['*' => Http::response(['error' => ['status' => 'INTERNAL']], $status)]);

    try {
        budgetedOver((billedDrivers()[$driver])())->generate(billedRequest());
    } catch (AiProviderException $e) {
        expect($e->billed)->toBeNull();
    }

    expect(DB::table('ai_usage_daily')->count())->toBe(0)->and(Cache::get('ai:budget:reservations', []))->toBe([]);
})->with([[500, 'gemini'], [400, 'gemini'], [500, 'anthropic'], [403, 'openai']]);

it('records nothing when a billed-looking answer has no usage numbers at all', function () {
    $body = wire('gemini', 'cut');
    unset($body['usageMetadata']);
    Http::fake(['*' => Http::response($body, 200)]);

    try {
        budgetedOver((billedDrivers()['gemini'])())->generate(billedRequest());
    } catch (AiProviderException $e) {
        expect($e->billed)->toBeNull();
    }

    expect(DB::table('ai_usage_daily')->count())->toBe(0);
});

it('moves the month totals that every later budget decision reads', function () {
    Http::fake(['*' => Http::response(wire('gemini', 'cut'), 200)]);
    $provider = budgetedOver((billedDrivers()['gemini'])());

    try {
        $provider->generate(billedRequest());
    } catch (AiProviderException) {
    }

    expect(app(AiUsageService::class)->monthTotals()['usd'])->toBe('0.00020');
});
