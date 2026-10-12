<?php

declare(strict_types=1);

use App\Modules\Ai\Drivers\AnthropicDriver;
use App\Modules\Ai\Drivers\GeminiDriver;
use App\Modules\Ai\Drivers\OpenAiDriver;
use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Exceptions\AiConfigurationException;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AiProviderFactory;
use App\Modules\Ai\Services\FakeAiProvider;
use Illuminate\Support\Facades\Http;

function aiConfig(string $provider): void
{
    config([
        'ai.provider' => $provider, 'ai.model' => 'test-model',
        'ai.providers.gemini.api_key' => 'k1', 'ai.providers.anthropic.api_key' => 'k2', 'ai.providers.openai.api_key' => 'k3',
    ]);
}

it('resolves the configured driver through the container', function (string $name, string $class) {
    aiConfig($name);

    expect(app(AiProvider::class))->toBeInstanceOf($class);
})->with([['gemini', GeminiDriver::class], ['anthropic', AnthropicDriver::class], ['openai', OpenAiDriver::class]]);

it('defaults to gemini', function () {
    expect(config('ai.provider'))->toBe('gemini');
});

it('fails loudly, and only when asked, for an unknown provider or a missing key/model', function () {
    config(['ai.provider' => 'gemini', 'ai.model' => null, 'ai.providers.gemini.api_key' => null]);

    expect(fn () => app(AiProvider::class))->toThrow(AiConfigurationException::class);
    expect(fn () => app(AiProviderFactory::class)->make('nonsense'))->toThrow(AiConfigurationException::class);
});

it('lets a FakeAiProvider replace the real binding without any config, DB or network', function () {
    Http::preventStrayRequests();
    config(['ai.provider' => 'gemini', 'ai.model' => null, 'ai.providers.gemini.api_key' => null]);
    $fake = (new FakeAiProvider)->replyJson(['headline' => 'x']);
    app()->instance(AiProvider::class, $fake);

    $r = app(AiProvider::class)->generate(new AiRequest('s', [AiMessage::user('hi')]));

    expect(app(AiProvider::class))->toBe($fake)->and($r->json)->toBe(['headline' => 'x']);
});

it('FakeAiProvider answers in scripted order and records every call', function () {
    $fake = (new FakeAiProvider)
        ->replyText('one', 10, 5)
        ->replyToolCall('get_x', ['k' => 1], 'c9')
        ->replyJson(['a' => 1]);
    $first = new AiRequest('sys', [AiMessage::user('1')]);
    $second = new AiRequest('sys', [AiMessage::user('2')], temperature: 0.9);

    $a = $fake->generate($first);
    $b = $fake->generate($second);
    $c = $fake->generate($first);

    expect($a->text)->toBe('one')->and($a->inputTokens)->toBe(10)->and($a->outputTokens)->toBe(5)
        ->and($b->toolCalls)->toEqual([new AiToolCall('c9', 'get_x', ['k' => 1])])
        ->and($c->json)->toBe(['a' => 1])
        ->and($fake->callCount())->toBe(3)->and($fake->calls()[1])->toBe($second)->and($fake->remaining())->toBe(0);
});

it('FakeAiProvider can simulate a provider error, a computed reply, and runs dry loudly', function () {
    $fake = (new FakeAiProvider)
        ->queue(new AiRateLimitException('limited', 'fake', 429, true))
        ->queue(fn (AiRequest $r) => FakeAiProvider::response(text: 'echo:'.$r->messages[0]->content, inputTokens: 7, outputTokens: 3, costUsd: '0.00100'));
    $req = new AiRequest('s', [AiMessage::user('hi')]);

    expect(fn () => $fake->generate($req))->toThrow(AiRateLimitException::class);

    $r = $fake->generate($req);
    expect($r->text)->toBe('echo:hi')->and($r->costUsd)->toBe('0.00100');
    expect(fn () => $fake->generate($req))->toThrow(LogicException::class);
    expect($fake->callCount())->toBe(3);
});

it('treats empty AI_* values (a copied .env.example) as "use the default", not as invalid', function () {
    $cfg = (function (array $env) {
        foreach ($env as $k => $v) {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
        $c = require base_path('config/ai.php');
        foreach (array_keys($env) as $k) {
            unset($_ENV[$k]);
            putenv($k);
        }

        return $c;
    })(['AI_PROVIDER' => '', 'AI_TIMEOUT' => '', 'AI_MAX_RETRIES' => '']);

    expect($cfg['provider'])->toBe('gemini')->and($cfg['timeout'])->toBe(60)->and($cfg['max_retries'])->toBe(2);
});
