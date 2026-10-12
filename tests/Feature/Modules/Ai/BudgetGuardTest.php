<?php

declare(strict_types=1);

use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Enums\BudgetState;
use App\Modules\Ai\Events\AiBudgetExceeded;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Exceptions\UnpricedModelException;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AiUsageService;
use App\Modules\Ai\Services\BudgetedAiProvider;
use App\Modules\Ai\Services\BudgetGuard;
use App\Modules\Ai\Services\BudgetStatusService;
use App\Modules\Ai\Services\FakeAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
| P7-03 — BudgetGuard / BudgetedAiProvider / usage ledger. GATE 4 ("budget hard-stop proven"): every test uses
| FakeAiProvider as the inner provider, so "the provider was never called" is a hard assertion, and nothing touches
| the network. Price is $1 per token (1,000,000 USD per 1M) to keep the arithmetic readable: the request built by
| req() has an input estimate of 6 bytes (system "ab" + message "cd" + the "[]" schema placeholder) and
| maxOutputTokens 10, so its worst case is 16 tokens = $16.
*/

const WORST_CASE_USD = 16;

function req(int $maxOut = 10): AiRequest
{
    return new AiRequest('ab', [AiMessage::user('cd')], maxOutputTokens: $maxOut);
}

function budgeted(FakeAiProvider $fake): BudgetedAiProvider
{
    return new BudgetedAiProvider($fake, app(BudgetGuard::class), app(AiUsageService::class));
}

function spend(string $cost = '15.00000', int $in = 4, int $out = 6): array
{
    return [FakeAiProvider::response(text: 'ok', inputTokens: $in, outputTokens: $out, costUsd: $cost)];
}

function usageRows(): int
{
    return DB::table('ai_usage_daily')->count();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    config([
        'ai.model' => 'test-model',
        'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '1000000', 'output' => '1000000']]],
        'ai.budget.monthly_usd' => '40',
        'ai.budget.warn_at' => '0.8',
        'ai.budget.monthly_tokens' => null,
        'ai.budget.allow_unpriced' => false,
        'ai.budget.reasoning_margin_tokens' => 0,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Tehran'));
});

it('HARD STOP: call N+1 is refused and the provider is never contacted', function () {
    $fake = (new FakeAiProvider)->queue(...spend())->queue(...spend())->queue(...spend());
    $provider = budgeted($fake);

    $provider->generate(req());   // used 0  + 16 <= 40 -> ok, records 15
    $provider->generate(req());   // used 15 + 16 <= 40 -> ok, records 15 (used 30)

    expect(fn () => $provider->generate(req()))->toThrow(BudgetExceededException::class, 'budget would be exceeded')  // 30 + 16 > 40
        ->and($fake->callCount())->toBe(2)
        ->and($fake->remaining())->toBe(1);
});

it('refuses a call whose WORST CASE passes the cap even though current spend is far below it', function () {
    config(['ai.budget.monthly_usd' => '10']);   // worst case is 16 > 10, usage is 0
    $fake = (new FakeAiProvider)->queue(...spend());

    expect(fn () => budgeted($fake)->generate(req()))->toThrow(BudgetExceededException::class)
        ->and($fake->callCount())->toBe(0)
        ->and(usageRows())->toBe(0);
});

it('prices the worst case from max_output_tokens: the same request fits with a smaller output ceiling', function () {
    config(['ai.budget.monthly_usd' => '10']);
    $fake = (new FakeAiProvider)->queue(...spend('1.00000'));

    budgeted($fake)->generate(req(maxOut: 4));   // 6 + 4 = 10 tokens = $10 <= 10

    expect($fake->callCount())->toBe(1);
});

it('dispatches AiBudgetExceeded on every hard stop (numbers only)', function () {
    Event::fake([AiBudgetExceeded::class]);
    config(['ai.budget.monthly_usd' => '10']);

    try {
        budgeted(new FakeAiProvider)->generate(req());
    } catch (BudgetExceededException) {
    }

    Event::assertDispatched(AiBudgetExceeded::class, fn (AiBudgetExceeded $e) => $e->reason === 'usd' && $e->month === '2026-10' && $e->capUsd === '10');
});

it('fails CLOSED for an unpriced model, naming the config key to fill', function (array $pricing) {
    config(['ai.pricing' => $pricing]);
    $fake = (new FakeAiProvider)->queue(...spend());

    try {
        budgeted($fake)->generate(req());
        $this->fail('expected UnpricedModelException');
    } catch (UnpricedModelException $e) {
        expect($e->getMessage())->toContain("config('ai.pricing.models.test-model')");
    }

    expect($fake->callCount())->toBe(0)->and(usageRows())->toBe(0);
})->with([
    'default is zero, model absent' => [['default' => ['input' => '0', 'output' => '0'], 'models' => []]],
    'model present but output is zero' => [['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '1', 'output' => '0']]]],
    'nothing configured' => [[]],
    'garbage price' => [['models' => ['test-model' => ['input' => 'free', 'output' => '1']]]],
]);

it('lets an unpriced model through only when config explicitly allows it (tests/Fake)', function () {
    config(['ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => []], 'ai.budget.allow_unpriced' => true]);
    $fake = (new FakeAiProvider)->queue(...spend('0.00000'));

    budgeted($fake)->generate(req());

    expect($fake->callCount())->toBe(1);
});

it('an unparseable cap means "refuse everything", never "unlimited"', function (mixed $cap) {
    config(['ai.budget.monthly_usd' => $cap]);

    expect(fn () => budgeted(new FakeAiProvider)->generate(req()))->toThrow(BudgetExceededException::class);
})->with(['abc', '-5', '', null, '1e3']);

it('enforces the independent token cap even when USD would allow the call', function () {
    config(['ai.budget.monthly_usd' => '1000000', 'ai.budget.monthly_tokens' => 100]);
    $fake = (new FakeAiProvider)->queue(...spend('1.00000', 50, 40))->queue(...spend());
    $provider = budgeted($fake);

    $provider->generate(req());   // 0 + 16 <= 100 -> ok, 90 tokens recorded

    expect(fn () => $provider->generate(req()))->toThrow(BudgetExceededException::class, 'token budget')   // 90 + 16 > 100
        ->and($fake->callCount())->toBe(1);
});

it('warns ONCE per month: not again while above the threshold, again in the next month', function () {
    config(['ai.budget.monthly_usd' => '100', 'ai.budget.warn_at' => '0.5']);
    $fake = (new FakeAiProvider)->queue(...spend('30.00000'))->queue(...spend('30.00000'))->queue(...spend('1.00000'))->queue(...spend('60.00000'));
    $provider = budgeted($fake);
    $alerts = fn () => DB::table('audit_logs')->where('action', 'alert.critical')->count();

    $provider->generate(req());              // 30 (< 50)
    expect($alerts())->toBe(0);

    $provider->generate(req());              // 60 (>= 50) -> warn
    expect($alerts())->toBe(1);

    $this->travel(3)->hours();               // past AlertService's own 60-minute dedupe: only the monthly marker is left
    $provider->generate(req());              // 61, still above -> no second warning this month
    expect($alerts())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Asia/Tehran'));
    $provider->generate(req());              // new month, 60 >= 50 -> warns again
    expect($alerts())->toBe(2);
});

it('raises the hard-stop alert once per month too', function () {
    config(['ai.budget.monthly_usd' => '10']);

    foreach (range(1, 3) as $_) {
        try {
            budgeted(new FakeAiProvider)->generate(req());
        } catch (BudgetExceededException) {
        }
    }

    expect(DB::table('audit_logs')->where('action', 'alert.critical')->count())->toBe(1);
});

it('never lets concurrent calls near the cap overshoot: in-flight reservations count', function () {
    // Cap 40, worst case 16 each. While A is inside the provider, B and C start (the interleaving two real
    // processes would produce). A + B reserve 32; C would make 48 > 40 and must be refused.
    $provider = null;
    $outcomes = [];
    $inner = new FakeAiProvider;
    $inner->queue(function () use (&$provider, &$outcomes) {
        foreach (['B', 'C'] as $name) {
            try {
                $outcomes[$name] = $provider->generate(req())->text;
            } catch (BudgetExceededException) {
                $outcomes[$name] = 'refused';
            }
        }

        return FakeAiProvider::response(text: 'A', costUsd: '15.00000');
    })->queue(...spend());

    $provider = budgeted($inner);
    $a = $provider->generate(req());

    expect($a->text)->toBe('A')
        ->and($outcomes)->toBe(['B' => 'ok', 'C' => 'refused'])
        ->and($inner->callCount())->toBe(2);

    // Everything settled: only real, recorded spend remains (A 15 + B 15), and it never passed the cap.
    expect((string) DB::table('ai_usage_daily')->sum('cost_usd'))->toBe('30.00000');
});

it('resets at the Asia/Tehran month boundary, not the UTC one', function () {
    config(['ai.budget.monthly_usd' => '20']);
    $fake = (new FakeAiProvider)->queue(...spend('15.00000'))->queue(...spend('15.00000'))->queue(...spend('15.00000'));
    $provider = budgeted($fake);

    // 20:00 UTC on Oct 31 is 23:30 in Tehran: still October.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00:00', 'UTC'));
    $provider->generate(req());
    expect(fn () => $provider->generate(req()))->toThrow(BudgetExceededException::class);   // 15 + 16 > 20

    // 21:00 UTC on Oct 31 is 00:30 on Nov 1 in Tehran: a new month, October's spend no longer counts.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 21:00:00', 'UTC'));
    $provider->generate(req());

    expect($fake->callCount())->toBe(2)
        ->and(DB::table('ai_usage_daily')->orderBy('date')->pluck('date')->all())->toBe(['2026-10-31', '2026-11-01']);
});

it('on a provider error records nothing and frees the reservation', function (Throwable $failure) {
    config(['ai.budget.monthly_usd' => '20']);   // only ONE worst case (16) fits at a time
    $fake = (new FakeAiProvider)->queue($failure)->queue($failure)->queue(...spend('1.00000'));
    $provider = budgeted($fake);

    foreach ([1, 2] as $_) {
        try {
            $provider->generate(req());
        } catch (Throwable) {
        }
    }

    expect(usageRows())->toBe(0);

    // A leaked reservation would make this third call refuse (16 reserved + 16 > 20).
    expect($provider->generate(req())->text)->toBe('ok')
        ->and($fake->callCount())->toBe(3);
})->with([
    'domain error' => [fn () => new AiRateLimitException('limited', 'fake', 429, true)],
    'unexpected error' => [fn () => new RuntimeException('boom')],
]);

it('upserts one row per Tehran day: requests, tokens and cost accumulate exactly (bcmath)', function () {
    config(['ai.budget.monthly_usd' => '1000000']);
    $fake = (new FakeAiProvider)
        ->queue(FakeAiProvider::response(text: 'a', inputTokens: 10, outputTokens: 5, costUsd: '0.00123'))
        ->queue(FakeAiProvider::response(text: 'b', inputTokens: 20, outputTokens: 7, costUsd: '0.00200'))
        ->queue(FakeAiProvider::response(text: 'c', inputTokens: 1, outputTokens: 1, costUsd: '0.10000'));
    $provider = budgeted($fake);

    $provider->generate(req());
    $provider->generate(req());
    $row = DB::table('ai_usage_daily')->first();

    expect(usageRows())->toBe(1)
        ->and($row->date)->toBe('2026-10-14')
        ->and($row->requests)->toBe(2)
        ->and($row->input_tokens)->toBe(30)
        ->and($row->output_tokens)->toBe(12)
        ->and($row->cost_usd)->toBe('0.00323');

    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Tehran'));
    $provider->generate(req());

    expect(usageRows())->toBe(2)->and((string) DB::table('ai_usage_daily')->sum('cost_usd'))->toBe('0.10323');
});

it('reports the month status read-only: ok -> warn -> blocked, with percentages and last usage', function () {
    config(['ai.budget.monthly_usd' => '100', 'ai.budget.warn_at' => '0.8', 'ai.budget.monthly_tokens' => 1000]);
    $status = fn () => app(BudgetStatusService::class)->status();

    $empty = $status();
    expect($empty->state)->toBe(BudgetState::Ok)->and($empty->usedUsd)->toBe('0')->and($empty->requests)->toBe(0)->and($empty->lastUsageDate)->toBeNull()
        ->and($empty->month)->toBe('2026-10')->and($empty->capUsd)->toBe('100')->and($empty->capTokens)->toBe(1000);

    $fake = (new FakeAiProvider)->queue(...spend('50.00000', 100, 100))->queue(...spend('32.00000', 100, 100))->queue(...spend('20.00000', 100, 100));
    $provider = budgeted($fake);

    $provider->generate(req());
    $half = $status();
    expect($half->state)->toBe(BudgetState::Ok)->and($half->percentUsd)->toBe(50.0)->and($half->percentTokens)->toBe(20.0)
        ->and($half->usedTokens)->toBe(200)->and($half->requests)->toBe(1)->and($half->lastUsageDate)->toBe('2026-10-14');

    $provider->generate(req());
    expect($status()->state)->toBe(BudgetState::Warn)->and($status()->percentUsd)->toBe(82.0);

    $provider->generate(req());
    expect($status()->state)->toBe(BudgetState::Blocked);
    expect(usageRows())->toBe(1);   // reading status never writes
});

it('status is read-only: calling it neither reserves nor records nor alerts', function () {
    app(BudgetStatusService::class)->status();

    expect(usageRows())->toBe(0)
        ->and(Cache::get('ai:budget:reservations'))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'alert.critical')->count())->toBe(0);
});

it('resolves the container AiProvider as the budget decorator', function () {
    config(['ai.provider' => 'gemini', 'ai.model' => 'test-model', 'ai.providers.gemini.api_key' => 'k']);

    expect(app(AiProvider::class))->toBeInstanceOf(BudgetedAiProvider::class);
});

it('adds the reasoning margin to the worst case: a call that only fits without it is refused', function () {
    config(['ai.budget.monthly_usd' => '20']);   // worst case 16 fits, 16 + 10 margin = 26 does not

    $without = (new FakeAiProvider)->queue(...spend('1.00000'));
    budgeted($without)->generate(req());
    expect($without->callCount())->toBe(1);

    config(['ai.budget.reasoning_margin_tokens' => 10]);
    $with = (new FakeAiProvider)->queue(...spend('1.00000'));

    expect(fn () => budgeted($with)->generate(req()))->toThrow(BudgetExceededException::class)
        ->and($with->callCount())->toBe(0);
});

it('applies the reasoning margin to the token cap too', function () {
    config(['ai.budget.monthly_usd' => '1000000', 'ai.budget.monthly_tokens' => 20, 'ai.budget.reasoning_margin_tokens' => 10]);

    expect(fn () => budgeted(new FakeAiProvider)->generate(req()))->toThrow(BudgetExceededException::class, 'token budget');   // 6 + 10 + 10 = 26 > 20
});

it('defaults to a conservative reasoning margin', function () {
    expect((require base_path('config/ai.php'))['budget']['reasoning_margin_tokens'])->toBeGreaterThanOrEqual(1024);
});

it('records the real usage including reasoning tokens (they are part of output tokens)', function () {
    config(['ai.budget.monthly_usd' => '1000000']);
    $fake = (new FakeAiProvider)->queue(FakeAiProvider::response(text: 'x', inputTokens: 10, outputTokens: 300, costUsd: '0.01000', reasoningTokens: 250));

    $r = budgeted($fake)->generate(req());

    expect($r->reasoningTokens)->toBe(250)->and((int) DB::table('ai_usage_daily')->value('output_tokens'))->toBe(300);
});

it('never lets the warning swallow the hard-stop alert, even inside the 60-minute window', function () {
    config(['ai.budget.monthly_usd' => '70', 'ai.budget.warn_at' => '0.5']);
    $fake = (new FakeAiProvider)->queue(...spend('40.00000'))->queue(...spend('30.00000'));
    $provider = budgeted($fake);
    $messages = fn () => DB::table('audit_logs')->where('action', 'alert.critical')->orderBy('id')->pluck('after')->map(fn ($a) => json_decode($a, true)['message'])->all();

    $provider->generate(req());   // 40 >= 35 -> warning
    $provider->generate(req());   // 70

    try {
        $provider->generate(req());   // 70 + 16 > 70 -> hard stop, seconds after the warning
    } catch (BudgetExceededException) {
    }

    $all = $messages();
    expect($all)->toHaveCount(2)->and($all[0])->toContain('reached')->and($all[1])->toContain('exhausted');
});
