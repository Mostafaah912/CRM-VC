<?php

declare(strict_types=1);

use App\Modules\Ai\Context\ContextBuilder;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Events\AiInsightGenerated;
use App\Modules\Ai\Exceptions\AiAuthenticationException;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Exceptions\AiResponseInvalidException;
use App\Modules\Ai\Exceptions\AnalystNotReadyException;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Exceptions\ManualRequestThrottledException;
use App\Modules\Ai\Exceptions\StaleDataException;
use App\Modules\Ai\Jobs\GenerateDailyBriefJob;
use App\Modules\Ai\Jobs\GenerateWeeklyReviewJob;
use App\Modules\Ai\Models\AiToolCall;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Services\AiUsageService;
use App\Modules\Ai\Services\AnalystService;
use App\Modules\Ai\Services\BudgetedAiProvider;
use App\Modules\Ai\Services\BudgetGuard;
use App\Modules\Ai\Services\FakeAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiToolsFixture;
use Tests\Support\AnomalySeries;

/*
| P7-08 — AnalystService end to end on REAL PostgreSQL (context through the real ai_readonly role, rows in the real tables), with a
| FakeAiProvider behind the real BudgetedAiProvider and Http::preventStrayRequests(): no key, no network. "Today" is pinned to
| Wednesday 2026-10-14 10:00 Tehran, so the daily period is 2026-10-13 and the newest complete day is 2026-10-13 (age 0).
*/

function groundedAnswer(string $contextJson, array $override = []): array
{
    $c = json_decode($contextJson, true);
    $r = $c['kpis']['windows'][0]['result'];

    return array_replace([
        'headline' => 'فروش دیروز نسبت به روز قبل تغییر کرد',
        'summary' => 'درآمد خالص دیروز '.number_format($r['current']['net_revenue']).' تومان بود.',
        'key_metrics' => [
            ['label' => 'درآمد خالص', 'value' => number_format($r['current']['net_revenue']).' تومان', 'change' => '', 'metric_refs' => ['kpis.windows.0.result.current.net_revenue']],
            ['label' => 'سفارش‌ها', 'value' => (string) $r['current']['orders'], 'change' => '', 'metric_refs' => ['kpis.windows.0.result.current.orders']],
        ],
        'insights' => [['text' => 'تعداد سفارش‌ها ثبت شد.', 'kind' => 'fact', 'severity' => 'info', 'metric_refs' => ['kpis.windows.0.result.change_percent.orders']]],
        'risks' => [], 'opportunities' => [],
        'recommendations' => [['action' => 'روند را دنبال کن.', 'rationale' => 'داده‌ی کافی نیست.', 'effort' => 'low', 'priority' => 'low']],
        'anomalies_explained' => [], 'data_gaps' => [], 'data_quality_note' => '', 'confidence' => 'medium',
    ], $override);
}

function currentContextJson(): string
{
    return app(ContextBuilder::class)->build(AiInsightType::DailyBrief)->json;
}

function okReply(array $answer, int $in = 3000, int $out = 700, string $cost = '0.00400'): AiResponse
{
    return FakeAiProvider::response(json: $answer, inputTokens: $in, outputTokens: $out, costUsd: $cost, model: 'test-model');
}

/** Binds a budgeted FakeAiProvider as THE provider and returns the fake. */
function useFake(FakeAiProvider $fake): FakeAiProvider
{
    app()->instance(AiProvider::class, new BudgetedAiProvider($fake, app(BudgetGuard::class), app(AiUsageService::class)));

    return $fake;
}

function analyst(): AnalystService
{
    return app(AnalystService::class);
}

function alertMessages(): array
{
    return DB::table('audit_logs')->where('action', 'alert.critical')->orderBy('id')->pluck('after')->map(fn ($a) => json_decode($a, true)['message'])->all();
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
    AiToolsFixture::loadDaily(AnomalySeries::make('2026-06-01', '2026-10-13', ['2026-10-07' => ['orders_count' => 130, 'net_revenue' => 26_000_000]]));
    config([
        'ai.enabled' => true, 'ai.provider' => 'gemini', 'ai.model' => 'test-model', 'ai.providers.gemini.api_key' => 'test-key-not-real',
        'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['test-model' => ['input' => '1', 'output' => '2']]],
        'ai.budget.monthly_usd' => '100', 'ai.budget.reasoning_margin_tokens' => 0, 'ai.budget.allow_unpriced' => false,
        'ai.generation.max_data_age_days' => 3, 'ai.manual.max_requests' => 5,
        'ai.context.pii_denylist' => ['Rezaei', 'علی رضایی', 'ali.rezaei'],
    ]);
    // a completed full metrics run that started after 03:00 Tehran today
    AiToolsFixture::admin()->table('metric_runs')->delete();
    AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'full', 'status' => 'completed', 'started_at' => '2026-10-14 03:10:00+03:30', 'finished_at' => '2026-10-14 03:20:00+03:30']);
});

afterEach(function () {
    AiToolsFixture::teardown();
});

it('stores the insight and its tool calls with exact values — one run, one transaction', function () {
    Event::fake([AiInsightGenerated::class]);
    $fake = useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));

    $r = analyst()->dailyBrief();
    $row = DB::table('ai_insights')->first();
    $context = app(ContextBuilder::class)->build(AiInsightType::DailyBrief);
    $calls = DB::table('ai_tool_calls')->orderBy('id')->get();

    expect($r->fromCache)->toBeFalse()->and($fake->callCount())->toBe(1)->and(DB::table('ai_insights')->count())->toBe(1)
        ->and($row->type)->toBe('daily_brief')->and($row->scope)->toBe('day:2026-10-13')->and($row->period_start)->toBe('2026-10-13')->and($row->period_end)->toBe('2026-10-13')
        ->and($row->provider)->toBe('fake')->and($row->model)->toBe('test-model')->and($row->prompt_version)->toBe('analyst.daily.v2')
        ->and($row->input_tokens)->toBe(3000)->and($row->output_tokens)->toBe(700)->and($row->cost_usd)->toBe('0.00400')->and($row->duration_ms)->toBeInt()
        ->and($row->requested_by)->toBeNull()->and(strlen($row->cache_key))->toBeLessThanOrEqual(120)->and($row->cache_key)->toBe($r->generated->cacheKey)
        ->and($row->cache_key)->toStartWith('daily_brief|analyst.daily.v2|')
        ->and(json_decode($row->payload, true))->toEqual(groundedAnswer($context->json))
        // ai_tool_calls: one row per ToolResult that is inside the context, all pointing at this insight
        ->and($calls->count())->toBe(count($context->toolResults))->and($r->toolCalls)->toBe($calls->count())
        ->and($calls->pluck('insight_id')->unique()->all())->toBe([$row->id])
        ->and($calls->pluck('tool_name')->all())->toBe(array_map(fn ($t) => $t->name, $context->toolResults))
        ->and($calls->first()->result_size)->toBeInt()->and(json_decode($calls->first()->arguments, true))->toBe($context->toolResults[0]->arguments)
        // spend was recorded by the budget layer
        ->and(DB::table('ai_usage_daily')->value('requests'))->toBe(1);

    Event::assertDispatched(AiInsightGenerated::class, fn ($e) => $e->insightId === $row->id && $e->scope === 'day:2026-10-13');
});

it('a weekly review is about the last Saturday-to-Friday week', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 07:30:00', 'Asia/Tehran'));   // Saturday
    AiToolsFixture::loadDaily(AnomalySeries::make('2026-06-01', '2026-10-16'));
    AiToolsFixture::admin()->table('metric_runs')->delete();
    AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'full', 'status' => 'completed', 'started_at' => '2026-10-17 03:10:00+03:30', 'finished_at' => '2026-10-17 03:20:00+03:30']);
    $json = app(ContextBuilder::class)->build(AiInsightType::WeeklyReview)->json;
    useFake((new FakeAiProvider)->queue(okReply(groundedAnswer($json))));

    analyst()->weeklyReview();
    $row = DB::table('ai_insights')->first();

    expect($row->type)->toBe('weekly_review')->and($row->scope)->toBe('week:2026-10-10..2026-10-16')->and($row->period_start)->toBe('2026-10-10')->and($row->period_end)->toBe('2026-10-16')->and($row->prompt_version)->toBe('analyst.weekly.v2');
});

it('stores requested_by for a manual run', function () {
    useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));
    $userId = DB::table('users')->insertGetId(['name' => 'T', 'email' => 't@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);

    analyst()->dailyBrief($userId);

    expect(DB::table('ai_insights')->value('requested_by'))->toBe($userId);
});

describe('cache', function () {
    it('an identical fresh insight is reused: no new row, no provider call, no spend', function () {
        $fake = useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));
        $first = analyst()->dailyBrief();
        $usage = DB::table('ai_usage_daily')->value('requests');

        $second = analyst()->dailyBrief();

        expect($second->fromCache)->toBeTrue()->and($second->insight->id)->toBe($first->insight->id)->and($second->generated)->toBeNull()->and($second->toolCalls)->toBe(0)
            ->and($fake->callCount())->toBe(1)->and(DB::table('ai_insights')->count())->toBe(1)->and(DB::table('ai_usage_daily')->value('requests'))->toBe($usage)
            ->and(DB::table('ai_tool_calls')->count())->toBe($first->toolCalls);
    });

    it('force regenerates: a second row, the old one keeps its history but gives up the unique cache key', function () {
        $answer = groundedAnswer(currentContextJson());
        $fake = useFake((new FakeAiProvider)->queue(okReply($answer))->queue(okReply($answer)));
        $first = analyst()->dailyBrief();

        $second = analyst()->dailyBrief(force: true);
        $rows = DB::table('ai_insights')->orderBy('id')->get();

        expect($second->fromCache)->toBeFalse()->and($fake->callCount())->toBe(2)->and($rows)->toHaveCount(2)->and($rows[0]->cache_key)->toBeNull()->and($rows[1]->cache_key)->toBe($second->generated->cacheKey)
            ->and(DB::table('ai_usage_daily')->value('requests'))->toBe(2)->and(DB::table('ai_tool_calls')->where('insight_id', $first->insight->id)->count())->toBe($first->toolCalls);
    });

    it('new data is a new insight', function () {
        $fake = useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson())))->queue(okReply(groundedAnswer(currentContextJson()))));
        analyst()->dailyBrief();
        AiToolsFixture::admin()->table('daily_metrics')->where('date', '2026-10-13')->update(['orders_count' => 77, 'net_revenue' => 15_400_000]);
        $fake->queue(okReply(groundedAnswer(currentContextJson())));

        $r = analyst()->dailyBrief();

        expect($r->fromCache)->toBeFalse()->and(DB::table('ai_insights')->count())->toBe(2);
    });
});

describe('failures leave nothing behind', function () {
    it('an answer that is invalid twice: no insight, no tool calls, an alert, exactly two provider calls', function () {
        $fake = useFake((new FakeAiProvider)->queue(FakeAiProvider::response(text: 'garbage'))->queue(FakeAiProvider::response(json: ['headline' => 'x'])));

        expect(fn () => analyst()->dailyBrief())->toThrow(AiResponseInvalidException::class)
            ->and($fake->callCount())->toBe(2)->and(DB::table('ai_insights')->count())->toBe(0)->and(DB::table('ai_tool_calls')->count())->toBe(0)
            ->and(alertMessages())->toContain('AI daily_brief generation failed: invalid_response')->and(implode(' ', alertMessages()))->not->toContain('garbage');
    });

    it('a wrong key_metrics number is retried once, then accepted when corrected — and is NEVER stored wrong', function () {
        $good = groundedAnswer(currentContextJson());
        $bad = $good;
        $bad['key_metrics'][0]['value'] = '1,234,567 تومان';
        $fake = useFake((new FakeAiProvider)->queue(okReply($bad))->queue(okReply($good)));

        $r = analyst()->dailyBrief();

        expect($r->generated->attempts)->toBe(2)->and($fake->callCount())->toBe(2)->and($fake->calls()[1]->messages[2]->content)->toContain('key_metrics.0.value: does not match the number at its first reference')
            ->and(json_decode(DB::table('ai_insights')->value('payload'), true))->toEqual($good)->and(DB::table('ai_usage_daily')->value('requests'))->toBe(2);
    });

    it('a wrong number twice is an invalid response: nothing stored', function () {
        $bad = groundedAnswer(currentContextJson());
        $bad['key_metrics'][1]['value'] = '999999';
        $fake = useFake((new FakeAiProvider)->queue(okReply($bad))->queue(okReply($bad)));

        expect(fn () => analyst()->dailyBrief())->toThrow(AiResponseInvalidException::class)->and($fake->callCount())->toBe(2)->and(DB::table('ai_insights')->count())->toBe(0);
    });

    it('a budget refusal: no provider call, no rows, no retry, and no "generation failed" alert', function () {
        config(['ai.budget.monthly_usd' => '0.00001']);
        $fake = useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));

        expect(fn () => analyst()->dailyBrief())->toThrow(BudgetExceededException::class)->and($fake->callCount())->toBe(0)->and(DB::table('ai_insights')->count())->toBe(0)
            ->and(DB::table('ai_tool_calls')->count())->toBe(0)->and(implode(' ', alertMessages()))->not->toContain('generation failed');
    });

    it('an unpriced model never reaches the provider: AnalystNotReady, no rows', function () {
        config(['ai.pricing.models' => []]);
        $fake = useFake(new FakeAiProvider);

        try {
            analyst()->dailyBrief();
            $this->fail('expected AnalystNotReadyException');
        } catch (AnalystNotReadyException $e) {
            expect($e->reason)->toBe('unpriced_model')->and($e->getMessage())->toContain('ai.pricing.models');
        }

        expect($fake->callCount())->toBe(0)->and(DB::table('ai_insights')->count())->toBe(0);
    });

    it('a provider error: nothing stored, an alert without any provider text', function () {
        $fake = useFake((new FakeAiProvider)->queue(new AiAuthenticationException('rejected the credentials: HTTP 401', 'fake', 401)));

        expect(fn () => analyst()->dailyBrief())->toThrow(AiAuthenticationException::class)->and(DB::table('ai_insights')->count())->toBe(0)
            ->and(alertMessages())->toContain('AI daily_brief generation failed: provider_error')->and($fake->callCount())->toBe(1);
    });

    it('if storing fails half-way the insight row is rolled back too', function () {
        useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));
        AiToolCall::creating(fn () => throw new RuntimeException('disk full'));

        try {
            analyst()->dailyBrief();
            $this->fail('expected the storage failure');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('disk full');
        } finally {
            AiToolCall::flushEventListeners();
        }

        expect(DB::table('ai_insights')->count())->toBe(0)->and(DB::table('ai_tool_calls')->count())->toBe(0);
    });
});

describe('guards', function () {
    it('AI_ENABLED=false (the default) refuses generation before anything is read or called', function () {
        config(['ai.enabled' => false]);
        $fake = useFake(new FakeAiProvider);

        expect(fn () => analyst()->dailyBrief())->toThrow(AnalystNotReadyException::class)->and($fake->callCount())->toBe(0)->and(DB::table('ai_usage_daily')->count())->toBe(0);
    });

    it('data older than the limit is refused (and alerted) before any provider call; at the limit it is allowed', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Asia/Tehran'));   // newest complete day 10-13, yesterday 10-17 => age 4
        $fake = useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));

        try {
            analyst()->dailyBrief();
            $this->fail('expected StaleDataException');
        } catch (StaleDataException $e) {
            expect($e->ageDays)->toBe(4)->and($e->maxAgeDays)->toBe(3);
        }

        expect($fake->callCount())->toBe(0)->and(DB::table('ai_insights')->count())->toBe(0)->and(alertMessages())->toContain('AI daily_brief generation failed: stale_data');

        config(['ai.generation.max_data_age_days' => 4]);
        $json = currentContextJson();
        $fake->queue(okReply(groundedAnswer($json)));

        expect(analyst()->dailyBrief()->fromCache)->toBeFalse()->and($fake->callCount())->toBe(1);
    });

    it('a manual request is throttled per requester, only provider calls count, other requesters and the scheduler are unaffected', function () {
        config(['ai.manual.max_requests' => 2]);
        $u7 = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $u8 = DB::table('users')->insertGetId(['name' => 'B', 'email' => 'b@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $answer = groundedAnswer(currentContextJson());
        $fake = useFake((new FakeAiProvider)->queue(okReply($answer))->queue(okReply($answer))->queue(okReply($answer))->queue(okReply($answer)));

        analyst()->dailyBrief($u7);                              // call 1
        analyst()->dailyBrief($u7);                              // cache hit: free, not counted
        analyst()->dailyBrief($u7, force: true);                 // call 2

        try {
            analyst()->dailyBrief($u7, force: true);             // would be call 3
            $this->fail('expected ManualRequestThrottledException');
        } catch (ManualRequestThrottledException $e) {
            expect($e->retryAfterSeconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(3600);
        }

        expect($fake->callCount())->toBe(2)
            ->and(analyst()->dailyBrief($u8, force: true)->fromCache)->toBeFalse()      // another user
            ->and(analyst()->dailyBrief(null, force: true)->fromCache)->toBeFalse()   // the scheduler / CLI has no requester
            ->and($fake->callCount())->toBe(4);
    });

    it('stores no customer-looking text: model output with a phone number and an email lands redacted', function () {
        $answer = groundedAnswer(currentContextJson(), ['summary' => 'تماس با 09121234567 و ali.rezaei@example.com لازم نیست']);
        useFake((new FakeAiProvider)->queue(okReply($answer)));

        $r = analyst()->dailyBrief();
        $stored = (string) DB::table('ai_insights')->value('payload');

        expect($r->generated->redactions)->toBe(1)->and(json_decode($stored, true)['summary'])->toBe('[redacted]');

        foreach (AiToolsFixture::PII as $needle) {
            expect($stored)->not->toContain($needle);
        }

        expect(preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $stored))->toBe(0)->and(preg_match('/(?<!\d)09\d{9}(?!\d)/', $stored))->toBe(0);
    });
});

describe('scheduled run (what the jobs call)', function () {
    it('generates when the nightly metrics run has finished today', function () {
        useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));

        $o = analyst()->runScheduled(AiInsightType::DailyBrief);

        expect($o->status)->toBe('generated')->and($o->insightId)->toBe((int) DB::table('ai_insights')->value('id'));
        expect(analyst()->runScheduled(AiInsightType::DailyBrief)->status)->toBe('cache_hit');
    });

    it('is skipped (not failed) while today\'s nightly chain has not finished — only yesterday\'s run exists', function () {
        AiToolsFixture::admin()->table('metric_runs')->delete();
        AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'full', 'status' => 'completed', 'started_at' => '2026-10-13 03:10:00+03:30', 'finished_at' => '2026-10-13 03:20:00+03:30']);
        AiToolsFixture::admin()->table('metric_runs')->insert(['mode' => 'full', 'status' => 'failed', 'error' => 'boom', 'started_at' => '2026-10-14 03:05:00+03:30', 'finished_at' => '2026-10-14 03:06:00+03:30']);
        $fake = useFake(new FakeAiProvider);

        $o = analyst()->runScheduled(AiInsightType::DailyBrief);

        expect($o->status)->toBe('skipped')->and($o->reason)->toBe('chain_not_finished')->and($fake->callCount())->toBe(0)->and(DB::table('ai_insights')->count())->toBe(0);
    });

    it('is a silent no-op when AI is off, logging the reason once', function () {
        config(['ai.enabled' => false]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message;
        });
        $fake = useFake(new FakeAiProvider);

        $a = analyst()->runScheduled(AiInsightType::DailyBrief);
        $b = analyst()->runScheduled(AiInsightType::WeeklyReview);

        expect($a->reason)->toBe('ai_disabled')->and($b->reason)->toBe('ai_disabled')->and($fake->callCount())->toBe(0)
            ->and(count(array_filter($logged, fn ($m) => str_contains($m, 'skipped: ai_disabled'))))->toBe(1);
    });

    it('maps every expected refusal to a skip, but lets a retryable provider error through so the queue can retry', function () {
        $answer = groundedAnswer(currentContextJson());

        useFake((new FakeAiProvider)->queue(FakeAiProvider::response(text: 'a'))->queue(FakeAiProvider::response(text: 'b')));
        expect(analyst()->runScheduled(AiInsightType::DailyBrief)->reason)->toBe('invalid_response');

        config(['ai.budget.monthly_usd' => '0.00001']);
        useFake(new FakeAiProvider);
        expect(analyst()->runScheduled(AiInsightType::DailyBrief)->reason)->toBe('budget_exceeded');
        config(['ai.budget.monthly_usd' => '100']);

        useFake((new FakeAiProvider)->queue(new AiAuthenticationException('x', 'fake', 401)));
        expect(analyst()->runScheduled(AiInsightType::DailyBrief)->reason)->toBe('provider_error');

        useFake((new FakeAiProvider)->queue(new AiRateLimitException('limited', 'fake', 429, true)));
        expect(fn () => analyst()->runScheduled(AiInsightType::DailyBrief))->toThrow(AiRateLimitException::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00', 'Asia/Tehran'));
        expect(analyst()->runScheduled(AiInsightType::DailyBrief)->reason)->toBe('chain_not_finished');   // no metrics run today in this clock

        expect(DB::table('ai_insights')->count())->toBe(0)->and($answer)->toBeArray();
    });

    it('the jobs are thin: they call AnalystService and nothing else, on the ai queue, unique, with small retry budgets', function () {
        useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));
        $job = new GenerateDailyBriefJob;

        $job->handle(analyst());

        expect(DB::table('ai_insights')->count())->toBe(1)->and($job->queue)->toBe('ai')->and($job->uniqueId())->toBe('ai-daily-brief')->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
            ->and($job->tries)->toBeLessThanOrEqual(3)->and($job->backoff)->not->toBeEmpty();

        $weekly = new GenerateWeeklyReviewJob;
        expect($weekly->queue)->toBe('ai')->and($weekly->uniqueId())->toBe('ai-weekly-review')->and($weekly->uniqueFor)->toBeGreaterThan($weekly->timeout);
    });
});

describe('hm:ai-generate', function () {
    it('prints a readable result for a fresh run, and says CACHE HIT (nothing called, nothing spent) for the next', function () {
        useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));

        expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief']))->toBe(0);
        $out = Artisan::output();

        expect($out)->toContain('GENERATED')->toContain('insight #')->toContain('day:2026-10-13')->toContain('headline: فروش دیروز')->toContain('findings: 1 insights')
            ->toContain('tokens in/out: 3000/700')->toContain('cost: 0.00400 USD')->toContain('tool calls stored:');

        expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief']))->toBe(0)->and(Artisan::output())->toContain('CACHE HIT');
    });

    it('--force and --no-cache both bypass the cache', function (string $flag) {
        $answer = groundedAnswer(currentContextJson());
        $fake = useFake((new FakeAiProvider)->queue(okReply($answer))->queue(okReply($answer)));
        Artisan::call('hm:ai-generate', ['type' => 'daily_brief']);

        Artisan::call('hm:ai-generate', ['type' => 'daily_brief', $flag => true]);

        expect(Artisan::output())->toContain('GENERATED')->and($fake->callCount())->toBe(2);
    })->with(['--force', '--no-cache']);

    it('explains each refusal in one plain sentence — never with a key, a prompt or model text', function () {
        $cases = [
            fn () => config(['ai.enabled' => false]),
            fn () => config(['ai.providers.gemini.api_key' => '']),
            fn () => config(['ai.pricing.models' => []]),
        ];
        $expected = ['Not ready (ai_disabled)', 'Not ready (missing_api_key)', 'Not ready (unpriced_model)'];

        foreach ($cases as $i => $break) {
            $break();

            expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief']))->toBe(1)->and(Artisan::output())->toContain($expected[$i]);

            config(['ai.enabled' => true, 'ai.providers.gemini.api_key' => 'test-key-not-real', 'ai.pricing.models.test-model' => ['input' => '1', 'output' => '2']]);
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Tehran'));
        expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief']))->toBe(1)->and(Artisan::output())->toContain('days old')->toContain('AI_MAX_DATA_AGE_DAYS');

        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Tehran'));
        config(['ai.budget.monthly_usd' => '0.00001']);
        useFake(new FakeAiProvider);
        expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief']))->toBe(1)->and(Artisan::output())->toContain('monthly budget')->not->toContain('test-key-not-real');

        config(['ai.budget.monthly_usd' => '100']);
        useFake((new FakeAiProvider)->queue(new AiAuthenticationException('x', 'fake', 401)));
        expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief']))->toBe(1)->and(Artisan::output())->toContain('rejected the API key')->not->toContain('test-key-not-real');
    });

    it('validates its arguments', function () {
        expect(Artisan::call('hm:ai-generate', ['type' => 'explain']))->toBe(1)->and(Artisan::output())->toContain('daily_brief or weekly_review');
        expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief', '--date' => '14-10-2026']))->toBe(1)->and(Artisan::output())->toContain('--date');
        expect(Artisan::call('hm:ai-generate', ['type' => 'daily_brief', '--date' => '2030-01-01']))->toBe(1);
    });

    it('--date labels the period it is about', function () {
        useFake((new FakeAiProvider)->queue(okReply(groundedAnswer(currentContextJson()))));

        Artisan::call('hm:ai-generate', ['type' => 'daily_brief', '--date' => '2026-10-12']);

        expect(DB::table('ai_insights')->value('scope'))->toBe('day:2026-10-12');
    });
});
