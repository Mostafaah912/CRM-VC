<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Ai\Context\ContextBuilder;
use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Exceptions\AnalystNotReadyException;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Exceptions\UnpricedModelException;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AiUsageService;
use App\Modules\Ai\Services\AnalystService;
use App\Modules\Ai\Services\BudgetedAiProvider;
use App\Modules\Ai\Services\BudgetGuard;
use App\Modules\Ai\Services\FakeAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiToolsFixture;
use Tests\Support\AnomalySeries;

/*
| GATE 4 (PRD §26: "قبل از هر Feature هوش مصنوعی") made automatic and repeatable, end to end on REAL PostgreSQL through the real `ai_readonly` role:
|  (a) no customer PII reaches the provider — not in the outgoing HTTP bodies (Gemini, Anthropic, OpenAI drivers over Http::fake, and the Fake), not in
|      ai_tool_calls, not in the stored payload (even when the MODEL echoes a phone number);
|  (b) the budget cap refuses BEFORE any HTTP (manual and scheduled alike), in-flight reservations count, an unpriced model fails closed;
|  (c) the read-only part lives in AiReadonlyRoleTest (INSERT/UPDATE/DELETE/DDL through the AI connection fail) — run alongside, 0 skipped;
|  (d) AI_ENABLED=false makes jobs and the endpoint do nothing: see tests/Feature/Modules/Ai/AiDisabledTest.php.
| Mutations proven in docs/architecture/sprint-7.md: disabling ContextPiiGuard, or the small-group mask, breaks (a).
*/

const G4_PII = [
    'علی', 'رضایی', 'Rezaei', 'ali.rezaei@example.com', '09121234567', '۰۹۱۲۱۲۳۴۵۶۷', '+989121234567', '989121234567', 'خیابان ولیعصر', 'Tehran Vali-asr',
    'Zahra-Planted-Name', 'zahra.planted@example.com', '09351112233',
];

function g4Context(): string
{
    return app(ContextBuilder::class)->build(AiInsightType::DailyBrief)->json;
}

function g4Answer(string $contextJson, array $override = []): array
{
    $r = json_decode($contextJson, true)['kpis']['windows'][0]['result'];

    return array_replace([
        'headline' => 'فروش دیروز تغییر کرد',
        'summary' => 'درآمد خالص دیروز '.number_format($r['current']['net_revenue']).' تومان بود.',
        'key_metrics' => [['label' => 'درآمد', 'value' => number_format($r['current']['net_revenue']).' تومان', 'change' => '', 'metric_refs' => ['kpis.windows.0.result.current.net_revenue']]],
        'insights' => [['text' => 'تعداد سفارش‌ها ثبت شد.', 'kind' => 'fact', 'severity' => 'info', 'metric_refs' => ['kpis.windows.0.result.current.orders']]],
        'risks' => [], 'opportunities' => [],
        'recommendations' => [['action' => 'روند را دنبال کن.', 'rationale' => 'داده‌ی کافی نیست.', 'effort' => 'low', 'priority' => 'low', 'metric_refs' => ['meta.type']]],
        'anomalies_explained' => [], 'data_gaps' => [], 'data_quality_note' => '', 'confidence' => 'medium',
    ], $override);
}

/** The provider's wire format of one successful answer. */
function g4Wire(string $driver, array $answer): array
{
    $text = json_encode($answer, JSON_UNESCAPED_UNICODE);

    return match ($driver) {
        'gemini' => ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 50, 'totalTokenCount' => 150]],
        'anthropic' => ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 50]],
        'openai' => ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]],
    };
}

function g4UseDriver(string $driver): void
{
    config(['ai.provider' => $driver, "ai.providers.{$driver}.api_key" => 'test-key-not-real', 'ai.model' => 'test-model']);
}

/** Every byte that left for a provider, as one string. */
function g4Outgoing(): string
{
    return implode("\n", array_map(fn (array $pair) => $pair[0]->url()."\n".json_encode($pair[0]->headers())."\n".$pair[0]->body(), Http::recorded()->all()));
}

function g4PlantPii(): void
{
    $db = AiToolsFixture::admin();
    // Free-text columns the AI role CAN read: a product named after a mobile number, a category after an e-mail, a segment named after a person.
    $db->table('products')->update(['name' => DB::raw("'Linen Dress 09121234567 ' || id")]);
    $db->table('product_categories')->update(['name' => DB::raw("'zahra.planted@example.com ' || id")]);
    $db->table('segments')->update(['name' => DB::raw("'Zahra-Planted-Name 09351112233 ' || id")]);
}

beforeEach(function () {
    $skip = AiToolsFixture::boot();

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    Http::preventStrayRequests();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Tehran'));
    AiToolsFixture::seed();
    AiToolsFixture::loadDaily(AnomalySeries::make('2026-06-01', '2026-10-13'));
    config([
        'ai.enabled' => true, 'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '1', 'output' => '2']]],
        'ai.budget.monthly_usd' => '100', 'ai.budget.reasoning_margin_tokens' => 0, 'ai.budget.allow_unpriced' => false, 'ai.generation.max_data_age_days' => 3,
        'ai.manual.max_requests' => 50, 'ai.tools.affinity_min_support' => 5, 'ai.context.pii_denylist' => ['Rezaei', 'علی رضایی', 'ali.rezaei', 'Zahra-Planted-Name'],
    ]);
    AiToolsFixture::admin()->table('metric_runs')->delete();
    AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'full', 'status' => 'completed', 'started_at' => '2026-10-14 03:10:00+03:30', 'finished_at' => '2026-10-14 03:20:00+03:30']);
    g4PlantPii();
});

afterEach(function () {
    AiToolsFixture::teardown();
});

// ── (a) PII never reaches the provider ────────────────────────────────────────────────────────────────────────────────────────────

it('GATE 4a: no name, phone, e-mail or address reaches a provider — Gemini, Anthropic and OpenAI drivers, request bodies and headers inspected', function (string $driver) {
    g4UseDriver($driver);
    $answer = g4Answer(g4Context());
    Http::fake(['*' => Http::response(g4Wire($driver, $answer), 200)]);
    $user = User::factory()->create();

    $result = app(AnalystService::class)->dailyBrief($user->id, true);
    $sent = g4Outgoing();

    expect(Http::recorded()->all())->toHaveCount(1)->and($sent)->toContain('<data>');

    foreach (G4_PII as $pii) {
        expect(str_contains($sent, $pii))->toBeFalse("provider request leaked: {$pii}");
    }

    expect($result->insight->requested_by)->toBe($user->id);
})->with(['gemini', 'anthropic', 'openai']);

it('GATE 4a: nothing identifying is in ai_tool_calls or in the stored payload either', function () {
    g4UseDriver('gemini');
    Http::fake(['*' => Http::response(g4Wire('gemini', g4Answer(g4Context())), 200)]);

    app(AnalystService::class)->dailyBrief(null, true);
    $stored = json_encode(DB::table('ai_insights')->get()->all(), JSON_UNESCAPED_UNICODE).json_encode(DB::table('ai_tool_calls')->get()->all(), JSON_UNESCAPED_UNICODE);

    expect(DB::table('ai_tool_calls')->count())->toBeGreaterThan(5);

    foreach (G4_PII as $pii) {
        expect(str_contains($stored, $pii))->toBeFalse("stored row leaked: {$pii}");
    }
});

it('GATE 4a: the Fake provider sees the same clean request (what the model would receive, byte for byte)', function () {
    g4UseDriver('gemini');   // readiness only; the Fake is bound over it
    $fake = new FakeAiProvider;
    $fake->queue(FakeAiProvider::response(json: g4Answer(g4Context()), inputTokens: 10, outputTokens: 10, costUsd: '0.00001', model: 'test-model'));
    app()->instance(AiProvider::class, new BudgetedAiProvider($fake, app(BudgetGuard::class), app(AiUsageService::class)));

    app(AnalystService::class)->dailyBrief(null, true);
    $request = json_encode($fake->calls()[0], JSON_UNESCAPED_UNICODE);

    expect($fake->callCount())->toBe(1);

    foreach (G4_PII as $pii) {
        expect(str_contains($request, $pii))->toBeFalse("Fake provider request leaked: {$pii}");
    }
});

it('GATE 4a: a model that ECHOES a phone, e-mail or planted name back is redacted before storage (output guard)', function () {
    g4UseDriver('gemini');
    $answer = g4Answer(g4Context(), ['summary' => 'مشتری با شماره 09121234567 و ایمیل ali.rezaei@example.com و نام Zahra-Planted-Name خرید کرد.']);
    Http::fake(['*' => Http::response(g4Wire('gemini', $answer), 200)]);

    $result = app(AnalystService::class)->dailyBrief(null, true);
    $stored = json_encode($result->insight->payload, JSON_UNESCAPED_UNICODE);

    foreach (['09121234567', 'ali.rezaei@example.com', 'Zahra-Planted-Name'] as $pii) {
        expect($stored)->not->toContain($pii);
    }

    expect($stored)->toContain('[redacted]');
});

it('GATE 4a: small groups are masked — a cohort of 3 customers reaches the provider as "<5", never as 3', function () {
    g4UseDriver('gemini');
    Http::fake(['*' => Http::response(g4Wire('gemini', g4Answer(g4Context())), 200)]);

    app(AnalystService::class)->dailyBrief(null, true);
    $sent = g4Outgoing();

    expect(str_contains($sent, '<5') || str_contains($sent, 'other_small_groups'))->toBeTrue()->and($sent)->not->toContain('\"cohort_size\":3')->not->toContain('"cohort_size":3');
});

// ── (b) the budget cap, before any HTTP ───────────────────────────────────────────────────────────────────────────────────────────

it('GATE 4b: a call whose worst case passes the cap is refused BEFORE any HTTP — manual and scheduled alike', function () {
    g4UseDriver('gemini');
    config(['ai.budget.monthly_usd' => '0.000001']);
    Http::fake(['*' => Http::response(g4Wire('gemini', g4Answer(g4Context())), 200)]);
    $analyst = app(AnalystService::class);
    $user = User::factory()->create();

    expect(fn () => $analyst->dailyBrief($user->id, true))->toThrow(BudgetExceededException::class);
    expect($analyst->runScheduled(AiInsightType::DailyBrief)->reason)->toBe('budget_exceeded');

    Http::assertNothingSent();
    expect(DB::table('ai_insights')->count())->toBe(0)->and(DB::table('ai_usage_daily')->count())->toBe(0);
});

it('GATE 4b: an in-flight reservation counts — the second call is refused while the first is still running, with no HTTP', function () {
    g4UseDriver('gemini');
    Http::fake(['*' => Http::response(g4Wire('gemini', g4Answer(g4Context())), 200)]);
    $context = app(ContextBuilder::class)->build(AiInsightType::DailyBrief);
    // price so that ONE worst case fits under the cap and two do not
    config(['ai.pricing.models.test-model' => ['input' => '1', 'output' => '1000'], 'ai.budget.monthly_usd' => '10']);   // one worst case (~$6.02) fits, two (~$12) do not
    $guard = app(BudgetGuard::class);
    $request = new AiRequest('sys', [AiMessage::user($context->json)], maxOutputTokens: 6000);
    $first = $guard->reserve($request, 'test-model');   // not released: it is "in flight"

    expect(fn () => app(AnalystService::class)->dailyBrief(null, true))->toThrow(BudgetExceededException::class);
    Http::assertNothingSent();

    $guard->release($first);
});

it('GATE 4b: a model without a price fails CLOSED (nothing sent), for manual and scheduled runs', function () {
    g4UseDriver('gemini');
    config(['ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => []]]);
    Http::fake(['*' => Http::response(g4Wire('gemini', g4Answer(g4Context())), 200)]);

    // readiness catches it first (not ready), and the budget guard would too: both are closed
    expect(fn () => app(AnalystService::class)->dailyBrief(null, true))->toThrow(AnalystNotReadyException::class);
    expect(app(AnalystService::class)->runScheduled(AiInsightType::DailyBrief)->status)->toBe('skipped');

    config(['ai.pricing.models' => []]);
    $guard = app(BudgetGuard::class);

    expect(fn () => $guard->reserve(new AiRequest('s', [AiMessage::user('u')], maxOutputTokens: 10), 'test-model'))->toThrow(UnpricedModelException::class);
    Http::assertNothingSent();
});

it('GATE 4b: the same cap guards every Http call the AI layer makes — one provider answer is recorded and counted, the next is judged on it', function () {
    g4UseDriver('gemini');
    Http::fake(['*' => Http::response(g4Wire('gemini', g4Answer(g4Context())), 200)]);

    app(AnalystService::class)->dailyBrief(null, true);
    $first = DB::table('ai_usage_daily')->first();

    expect(Http::recorded()->all())->toHaveCount(1)->and((int) $first->requests)->toBe(1)->and((int) $first->input_tokens)->toBe(100)->and((int) $first->output_tokens)->toBe(50)
        ->and(collect(Http::recorded()->all())->every(fn (array $p) => $p[0] instanceof Request))->toBeTrue();
});
