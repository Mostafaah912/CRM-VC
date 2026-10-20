<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Jobs\GenerateDailyBriefJob;
use App\Modules\Ai\Jobs\GenerateManualInsightJob;
use App\Modules\Ai\Jobs\GenerateWeeklyReviewJob;
use App\Modules\Ai\Services\AnalystService;
use App\Modules\Ai\Services\ManualGenerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SystemPageFixtures as Fx;

/*
| GATE 4d — AI_ENABLED=false (the default): the scheduled jobs, the manual job, the endpoint and the commands make NO provider call and write no insight.
| Http::preventStrayRequests() + Http::assertNothingSent() + no key configured: any attempt would throw or show up.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    config(['ai.enabled' => false, 'ai.provider' => 'gemini', 'ai.model' => 'm', 'ai.providers.gemini.api_key' => 'k', 'inertia.ssr.enabled' => false]);
});

it('is OFF by default in config (the shipped value), without any env', function () {
    expect((require base_path('config/ai.php'))['enabled'])->toBeFalse();
});

it('the scheduled daily and weekly jobs skip with ai_disabled and send nothing', function () {
    (new GenerateDailyBriefJob)->handle(app(AnalystService::class));
    (new GenerateWeeklyReviewJob)->handle(app(AnalystService::class));

    expect(app(AnalystService::class)->runScheduled(AiInsightType::DailyBrief)->reason)->toBe('ai_disabled')
        ->and(app(AnalystService::class)->runScheduled(AiInsightType::WeeklyReview)->reason)->toBe('ai_disabled')
        ->and(DB::table('ai_insights')->count())->toBe(0);
    Http::assertNothingSent();
});

it('the manual job ends in state failed/ai_disabled and sends nothing', function () {
    $user = User::factory()->create();
    $manual = app(ManualGenerationService::class);

    (new GenerateManualInsightJob(AiInsightType::DailyBrief, $user->id, true))->handle($manual);

    expect($manual->state(AiInsightType::DailyBrief))->toMatchArray(['status' => 'failed', 'code' => 'ai_disabled']);
    Http::assertNothingSent();
});

it('the generate endpoint refuses with 422 ai_disabled, queues nothing and sends nothing', function () {
    Queue::fake();
    $user = Fx::userWith('ai.view', 'ai.request');

    $this->actingAs($user)->postJson('/ai/analyst/generate', ['type' => 'daily_brief'])->assertStatus(422)->assertJson(['code' => 'ai_disabled']);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('hm:ai-generate refuses and sends nothing', function () {
    $this->artisan('hm:ai-generate', ['type' => 'daily_brief'])->assertFailed();

    Http::assertNothingSent();
});
