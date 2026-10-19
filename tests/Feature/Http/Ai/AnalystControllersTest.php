<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Jobs\GenerateManualInsightJob;
use App\Modules\Ai\Services\ManualGenerationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P7-09a — the «تحلیلگر هوشمند» page, its history and status endpoints (behind ai.view) and the manual-generation endpoint (behind
| ai.request). No test here can reach a provider: Http::preventStrayRequests() and Queue::fake() — the endpoint only queues.
| Cost/tokens/model/prompt version exist in a response only for a holder of ai.view_cost.
*/

const SECRET_MARK = 'SECRET-CACHE-KEY-VALUE';

function seedInsight(string $type = 'daily_brief', array $payload = [], array $overrides = []): int
{
    $payload += [
        'headline' => 'فروش دیروز رشد کرد', 'summary' => 'خلاصه', 'confidence' => 'medium', 'data_quality_note' => '', 'data_gaps' => [],
        'key_metrics' => [['label' => 'درآمد', 'value' => '1,000 تومان', 'change' => '+5.00%', 'metric_refs' => ['kpis.x']]],
        'insights' => [['text' => 'یک یافته', 'kind' => 'fact', 'severity' => 'info', 'metric_refs' => ['kpis.x']]],
        'risks' => [['text' => 'یک ریسک', 'severity' => 'warning', 'metric_refs' => []]],
        'opportunities' => [['text' => 'یک فرصت', 'metric_refs' => []]],
        'recommendations' => [['action' => 'یک اقدام', 'rationale' => 'دلیل', 'effort' => 'low', 'priority' => 'high', 'metric_refs' => []]],
        'anomalies_explained' => [],
    ];

    return DB::table('ai_insights')->insertGetId($overrides + [
        'type' => $type, 'scope' => 'day:2026-10-13', 'period_start' => '2026-10-13', 'period_end' => '2026-10-13',
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'provider' => 'gemini', 'model' => 'gemini-test', 'prompt_version' => 'analyst.daily.v4',
        'input_tokens' => 9000, 'output_tokens' => 2500, 'cost_usd' => '0.01500', 'duration_ms' => 18000, 'cache_key' => SECRET_MARK.'-'.uniqid(), 'requested_by' => null,
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();
    config([
        'inertia.ssr.enabled' => false,   // an SSR render is an HTTP call, which preventStrayRequests() above refuses
        'ai.enabled' => true, 'ai.provider' => 'gemini', 'ai.model' => 'gemini-test', 'ai.providers.gemini.api_key' => 'test-key-not-real',
        'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['gemini-test' => ['input' => '1', 'output' => '2']]],
        'ai.manual.max_requests' => 5, 'ai.budget.monthly_usd' => '10',
    ]);
});

// ── page ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('refuses a guest and a user without ai.view on every read endpoint', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
    $this->actingAs(Fx::userWith('dashboard.view'))->get($uri)->assertForbidden();
})->with(['/ai/analyst', '/ai/analyst/history?type=daily_brief', '/ai/analyst/status?type=daily_brief']);

it('renders the page for ai.view: latest per type, state, readiness, freshness — and no cost', function () {
    seedInsight('daily_brief');
    seedInsight('weekly_review', ['headline' => 'مرور هفته'], ['scope' => 'week:2026-10-03..2026-10-09']);

    $this->actingAs(Fx::userWith('ai.view'))->get('/ai/analyst')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('ai/analyst')
        ->where('data.enabled', true)->where('data.readiness.ready', true)
        ->where('data.latest.daily_brief.headline', 'فروش دیروز رشد کرد')
        ->where('data.latest.weekly_review.headline', 'مرور هفته')
        ->where('data.latest.daily_brief.key_metrics.0.change', '+5.00%')
        ->where('data.generation.daily_brief.status', 'idle')
        ->has('data.freshness')->has('data.manual')->has('data.last_run')
        ->missing('data.budget')->missing('data.latest.daily_brief.technical'));
});

it('shows an empty state when there is no insight yet', function () {
    $this->actingAs(Fx::userWith('ai.view'))->get('/ai/analyst')->assertInertia(fn (Assert $p) => $p
        ->where('data.latest.daily_brief', null)->where('data.latest.weekly_review', null)->where('data.last_run', null));
});

it('reports AI off with a plain Persian sentence, never a key or a setting value', function () {
    config(['ai.enabled' => false]);
    $body = $this->actingAs(Fx::userWith('ai.view'))->get('/ai/analyst')->assertInertia(fn (Assert $p) => $p
        ->where('data.enabled', false)->where('data.readiness.ready', false)->where('data.readiness.code', 'ai_disabled')
        ->where('data.readiness.message', 'تحلیلگر هوشمند غیرفعال است.'))->getContent();

    expect($body)->not->toContain('test-key-not-real')->not->toContain('AI_ENABLED');
});

it('hides cost, tokens, model, prompt version and the budget from everyone without ai.view_cost, and shows them to a holder', function () {
    seedInsight();
    DB::table('ai_usage_daily')->insert(['date' => now('Asia/Tehran')->toDateString(), 'requests' => 2, 'input_tokens' => 10, 'output_tokens' => 5, 'cost_usd' => '0.50000']);

    $plain = $this->actingAs(Fx::userWith('ai.view', 'ai.request'))->get('/ai/analyst');
    $admin = $this->actingAs(Fx::userWith('ai.view', 'ai.view_cost'))->get('/ai/analyst');

    foreach (['gemini-test', 'analyst.daily.v4', '0.01500', '9000', 'cost_usd', 'used_usd'] as $secret) {
        expect($plain->getContent())->not->toContain($secret);
    }

    $admin->assertInertia(fn (Assert $p) => $p
        ->where('data.latest.daily_brief.technical.model', 'gemini-test')->where('data.latest.daily_brief.technical.prompt_version', 'analyst.daily.v4')
        ->where('data.latest.daily_brief.technical.input_tokens', 9000)->where('data.latest.daily_brief.technical.cost_usd', '0.01500')
        ->where('data.budget.cap_usd', '10')->where('data.budget.requests', 2)->where('data.budget.state', 'ok'));
});

it('never returns the prompt, the context, the cache key or the raw payload — only whitelisted fields (also from history)', function () {
    seedInsight('daily_brief', ['hidden_prompt' => 'SYSTEM PROMPT TEXT', 'context' => ['secret' => 'RAW CONTEXT']]);
    $user = Fx::userWith('ai.view', 'ai.view_cost');

    foreach (['/ai/analyst', '/ai/analyst/history?type=daily_brief'] as $uri) {
        $body = $this->actingAs($user)->get($uri)->getContent();

        expect($body)->not->toContain(SECRET_MARK)->not->toContain('SYSTEM PROMPT TEXT')->not->toContain('RAW CONTEXT')->not->toContain('metric_refs')->not->toContain('cache_key');
    }
});

it('surfaces a confidence downgrade and ungrounded numbers recorded by the validator', function () {
    seedInsight('daily_brief', ['confidence' => 'medium', 'meta' => [
        'adjustments' => [['field' => 'confidence', 'from' => 'high', 'to' => 'medium', 'reasons' => ['ungrounded_numbers', 'data_quality_warnings']]],
        'ungrounded_numbers' => ['7392'],
    ]]);

    $this->actingAs(Fx::userWith('ai.view'))->get('/ai/analyst')->assertInertia(fn (Assert $p) => $p
        ->where('data.latest.daily_brief.confidence_adjustment', ['from' => 'high', 'to' => 'medium', 'reasons' => ['ungrounded_numbers', 'data_quality_warnings']])
        ->where('data.latest.daily_brief.ungrounded_numbers', ['7392']));
});

it('flags an insight about an earlier day than the newest data as outdated, using Jalali dates', function () {
    seedInsight('daily_brief');

    $this->actingAs(Fx::userWith('ai.view'))->get('/ai/analyst')->assertInertia(fn (Assert $p) => $p
        ->where('data.latest.daily_brief.period.start', '1405/07/21')->where('data.latest.daily_brief.period.end', '1405/07/21')
        ->where('data.latest.daily_brief.created_at', fn ($v) => preg_match('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}$#', (string) $v) === 1));
});

it('tells the screen about a recent failure or refusal from the audit log — a sentence, not the stored detail', function () {
    DB::table('audit_logs')->insert([
        'user_id' => null, 'actor_type' => 'system', 'action' => 'alert.critical', 'auditable_type' => 'alert', 'auditable_id' => 0, 'source' => 'alerts', 'created_at' => now(),
        'after' => json_encode(['kind' => 'ai_generation_failure', 'message' => 'AI daily_brief generation failed: stale_data', 'context' => ['type' => 'daily_brief', 'reason' => 'stale_data', 'detail' => 'data is 9 days old (limit 3)']]),
    ]);

    $body = $this->actingAs(Fx::userWith('ai.view'))->get('/ai/analyst')->assertInertia(fn (Assert $p) => $p
        ->where('data.recent_problem.reason', 'stale_data')->where('data.recent_problem.type', 'daily_brief')
        ->where('data.recent_problem.message', 'تحلیل به‌خاطر قدیمی بودن داده انجام نشد.'))->getContent();

    expect($body)->not->toContain('9 days old');
});

it('does not report an old failure (older than three days)', function () {
    DB::table('audit_logs')->insert([
        'user_id' => null, 'actor_type' => 'system', 'action' => 'alert.critical', 'auditable_type' => 'alert', 'auditable_id' => 0, 'source' => 'alerts', 'created_at' => now()->subDays(5),
        'after' => json_encode(['kind' => 'ai_generation_failure', 'message' => 'x', 'context' => ['type' => 'daily_brief', 'reason' => 'stale_data']]),
    ]);

    $this->actingAs(Fx::userWith('ai.view'))->get('/ai/analyst')->assertInertia(fn (Assert $p) => $p->where('data.recent_problem', null));
});

// ── history / status ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('pages the history of one type, newest first, and rejects a bad type or page size', function () {
    foreach (range(1, 12) as $i) {
        seedInsight('daily_brief', ['headline' => "روز {$i}"]);
    }
    seedInsight('weekly_review', ['headline' => 'هفتگی']);
    $user = Fx::userWith('ai.view');

    $first = $this->actingAs($user)->getJson('/ai/analyst/history?type=daily_brief&per_page=5')->assertOk()->json();
    $last = $this->actingAs($user)->getJson('/ai/analyst/history?type=daily_brief&per_page=5&page=3')->json();

    expect($first['total'])->toBe(12)->and($first['last_page'])->toBe(3)->and($first['data'])->toHaveCount(5)->and($first['data'][0]['headline'])->toBe('روز 12')
        ->and($last['data'])->toHaveCount(2)->and($last['data'][1]['headline'])->toBe('روز 1');

    $this->actingAs($user)->getJson('/ai/analyst/history?type=explain')->assertStatus(422);
    $this->actingAs($user)->getJson('/ai/analyst/history')->assertStatus(422);
    $this->actingAs($user)->getJson('/ai/analyst/history?type=daily_brief&per_page=500')->assertStatus(422);
});

it('reports the manual-generation state, idle by default', function () {
    $user = Fx::userWith('ai.view');

    $this->actingAs($user)->getJson('/ai/analyst/status?type=daily_brief')->assertOk()->assertExactJson(['status' => 'idle']);

    Cache::put('ai:manual:state:daily_brief', ['status' => 'failed', 'by' => 1, 'code' => 'budget_exceeded', 'message' => 'بودجه‌ی ماهانه‌ی هوش مصنوعی تمام شده است.'], 600);

    $this->actingAs($user)->getJson('/ai/analyst/status?type=daily_brief')->assertExactJson(['status' => 'failed', 'code' => 'budget_exceeded', 'message' => 'بودجه‌ی ماهانه‌ی هوش مصنوعی تمام شده است.']);
    $this->actingAs($user)->getJson('/ai/analyst/status?type=weekly_review')->assertExactJson(['status' => 'idle']);
});

// ── manual generation ────────────────────────────────────────────────────────────────────────────────────────────────────────────

function generate(object $test, User $user, array $body = ['type' => 'daily_brief']): TestResponse
{
    return $test->actingAs($user)->postJson('/ai/analyst/generate', $body);
}

it('refuses a guest, a viewer without ai.request, and an unknown type', function () {
    $this->postJson('/ai/analyst/generate', ['type' => 'daily_brief'])->assertUnauthorized();
    generate($this, Fx::userWith('ai.view'))->assertForbidden();
    generate($this, Fx::userWith('ai.request'), ['type' => 'explain'])->assertStatus(422);
    generate($this, Fx::userWith('ai.request'), [])->assertStatus(422);
    Queue::assertNothingPushed();
});

it('queues one job for the user, answers 202 with the state and audits the request — it calls no provider', function () {
    $user = Fx::userWith('ai.request');

    generate($this, $user, ['type' => 'weekly_review', 'force' => true])->assertStatus(202)->assertJson(['status' => 'queued']);

    Queue::assertPushed(GenerateManualInsightJob::class, fn ($j) => $j->type === AiInsightType::WeeklyReview && $j->userId === $user->id && $j->force === true && $j->queue === 'ai');
    expect(DB::table('audit_logs')->where('action', 'ai.generate.requested')->where('user_id', $user->id)->count())->toBe(1);
});

it('does not queue a second run of the same type while one is already queued or running', function () {
    $user = Fx::userWith('ai.request');

    generate($this, $user)->assertStatus(202);
    generate($this, $user)->assertStatus(202)->assertJson(['status' => 'queued', 'already_running' => true]);

    Queue::assertPushed(GenerateManualInsightJob::class, 1);
    generate($this, $user, ['type' => 'weekly_review'])->assertStatus(202);   // another type is independent
    Queue::assertPushed(GenerateManualInsightJob::class, 2);
});

it('refuses with a Persian sentence and a code when AI is off or not configured — nothing is queued', function (array $config, string $code, string $message) {
    config($config);

    generate($this, Fx::userWith('ai.request'))->assertStatus(422)->assertExactJson(['code' => $code, 'message' => $message]);
    Queue::assertNothingPushed();
})->with([
    'AI off' => [['ai.enabled' => false], 'ai_disabled', 'تحلیلگر هوشمند غیرفعال است.'],
    'no key' => [['ai.providers.gemini.api_key' => ''], 'not_ready', 'کلید یا ارائه‌دهنده‌ی هوش مصنوعی تنظیم نشده است.'],
    'no price' => [['ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => []]], 'not_ready', 'قیمت مدل هوش مصنوعی تنظیم نشده است، پس بودجه قابل کنترل نیست.'],
]);

it('throttles manual generation per user: after the limit it answers 429 in Persian and queues nothing', function () {
    $user = Fx::userWith('ai.request');
    $other = Fx::userWith('ai.request');

    foreach (range(1, 5) as $_) {
        RateLimiter::hit('ai:manual:'.$user->id, 3600);
    }

    generate($this, $user)->assertStatus(429)->assertExactJson(['code' => 'throttled', 'message' => 'سقف درخواست دستی پر شده است؛ بعداً دوباره تلاش کنید.']);
    Queue::assertNothingPushed();
    generate($this, $other)->assertStatus(202);   // someone else is unaffected
});

it('shows the remaining manual attempts on the page', function () {
    $user = Fx::userWith('ai.view', 'ai.request');
    RateLimiter::hit('ai:manual:'.$user->id, 3600);
    RateLimiter::hit('ai:manual:'.$user->id, 3600);

    $this->actingAs($user)->get('/ai/analyst')->assertInertia(fn (Assert $p) => $p->where('data.manual', ['max_per_window' => 5, 'window_minutes' => 60, 'remaining' => 3]));
});

it('exposes no explain endpoint (PRD §19 defines none) and no way to pick a type other than daily_brief / weekly_review', function () {
    $this->actingAs(Fx::userWith('ai.view', 'ai.request'))->post('/ai/analyst/explain')->assertNotFound();
    expect(app(ManualGenerationService::class)->state(AiInsightType::Explain))->toBe(['status' => 'idle']);
});
