<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P7-09b — the dashboard widget (GET /ai/analyst/widget, ai.view) and the Customer 360 aggregate panel (GET /customers/{id}/analyst-panel,
| customers.view; segments part needs segments.view, insight part needs ai.view). Neither calls an AI provider (Http::preventStrayRequests) and the
| panel carries no per-customer value.
*/

function widgetInsight(array $payload = [], array $overrides = []): int
{
    return DB::table('ai_insights')->insertGetId($overrides + [
        'type' => 'daily_brief', 'scope' => 'day:2026-10-18', 'period_start' => '2026-10-18', 'period_end' => '2026-10-18',
        'payload' => json_encode($payload + ['headline' => 'رشد درآمد همراه با افت AOV', 'confidence' => 'medium', 'summary' => 'x', 'key_metrics' => [], 'insights' => [], 'risks' => [], 'opportunities' => [], 'recommendations' => [], 'anomalies_explained' => [], 'data_quality_note' => '', 'data_gaps' => []], JSON_UNESCAPED_UNICODE),
        'provider' => 'gemini', 'model' => 'gemini-test', 'prompt_version' => 'analyst.daily.v4', 'input_tokens' => 9000, 'output_tokens' => 2500, 'cost_usd' => '0.01500',
        'duration_ms' => 1, 'cache_key' => 'WIDGET-KEY-'.uniqid(), 'requested_by' => null, 'created_at' => now(),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();
    config(['ai.enabled' => true, 'ai.provider' => 'gemini', 'ai.model' => 'gemini-test', 'ai.providers.gemini.api_key' => 'test-key-not-real',
        'ai.pricing' => ['default' => ['input' => '0', 'output' => '0'], 'models' => ['gemini-test' => ['input' => '1', 'output' => '2']]]]);
});

it('refuses a guest and a user without ai.view on the widget endpoint', function () {
    $this->getJson('/ai/analyst/widget')->assertUnauthorized();
    $this->actingAs(Fx::userWith('dashboard.view'))->getJson('/ai/analyst/widget')->assertForbidden();
});

it('serves the newest daily brief in short form with the live flags, and nothing costly or raw', function () {
    widgetInsight([], ['scope' => 'day:2026-10-17', 'period_start' => '2026-10-17', 'period_end' => '2026-10-17']);
    $id = widgetInsight(['headline' => 'تازه‌ترین', 'meta' => ['adjustments' => [['field' => 'confidence', 'from' => 'high', 'to' => 'medium', 'reasons' => ['data_quality_warnings']]]]]);
    widgetInsight(['headline' => 'هفتگی'], ['type' => 'weekly_review']);

    $r = $this->actingAs(Fx::userWith('ai.view', 'ai.view_cost'))->getJson('/ai/analyst/widget')->assertOk();

    expect($r->json('enabled'))->toBeTrue()->and($r->json('readiness.ready'))->toBeTrue()
        ->and($r->json('latest.id'))->toBe($id)->and($r->json('latest.headline'))->toBe('تازه‌ترین')->and($r->json('latest.confidence_reduced'))->toBeTrue()
        ->and($r->json('latest.period.end'))->toBe('1405/07/26')->and($r->json('freshness'))->toHaveKeys(['stale', 'orders_sync_behind', 'orders_behind_hours', 'as_of'])
        ->and($r->getContent())->not->toContain('gemini-test')->not->toContain('0.01500')->not->toContain('WIDGET-KEY')->not->toContain('metric_refs')->not->toContain('technical');
});

it('shows the states: AI off, no insight yet, a recent failure', function () {
    $user = Fx::userWith('ai.view');
    config(['ai.enabled' => false]);

    $off = $this->actingAs($user)->getJson('/ai/analyst/widget')->assertOk();
    expect($off->json('enabled'))->toBeFalse()->and($off->json('latest'))->toBeNull()->and($off->json('readiness.message'))->toBe('تحلیلگر هوشمند غیرفعال است.');

    DB::table('audit_logs')->insert([
        'user_id' => null, 'actor_type' => 'system', 'action' => 'alert.critical', 'auditable_type' => 'alert', 'auditable_id' => 0, 'source' => 'alerts', 'created_at' => now(),
        'after' => json_encode(['kind' => 'ai_generation_failure', 'message' => 'x', 'context' => ['type' => 'daily_brief', 'reason' => 'orders_sync_behind', 'detail' => 'secret detail']]),
    ]);
    $failed = $this->actingAs($user)->getJson('/ai/analyst/widget');

    expect($failed->json('recent_problem.message'))->toBe('تحلیل به‌خاطر عقب‌ماندن همگام‌سازی سفارش‌ها (روز ناقص) انجام نشد.')->and($failed->getContent())->not->toContain('secret detail');
});

it('is read-only and cheap: it queues nothing and calls no provider', function () {
    widgetInsight();

    $this->actingAs(Fx::userWith('ai.view'))->getJson('/ai/analyst/widget')->assertOk();

    Queue::assertNothingPushed();
});

// ── Customer 360 panel ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

function panelSegment(string $name, int $members, array $metricsRows): Segment
{
    $segment = Segment::factory()->create(['name' => $name, 'member_count' => $members]);

    foreach ($metricsRows as [$customer, $recency, $monetary]) {
        DB::table('customer_metrics')->updateOrInsert(['customer_id' => $customer->id], ['recency_days' => $recency, 'monetary' => $monetary]);
        DB::table('segment_members')->insert(['segment_id' => $segment->id, 'customer_id' => $customer->id, 'added_at' => now()]);
    }

    return $segment;
}

it('refuses a guest and a user without customers.view on the panel', function () {
    $c = Customer::factory()->create();

    $this->getJson("/customers/{$c->id}/analyst-panel")->assertUnauthorized();
    $this->actingAs(Fx::userWith('ai.view'))->getJson("/customers/{$c->id}/analyst-panel")->assertForbidden();
});

it('returns the customer\'s segments with SEGMENT-level averages, and a pointer to the latest insight — nothing about the customer\'s own values', function () {
    $me = Customer::factory()->create(['first_name' => 'Zahra', 'last_name' => 'Distinct-Surname', 'phone_normalized' => '989123456789']);
    $others = Customer::factory()->count(5)->create();
    panelSegment('وفادار', 6, [[$me, 10, 1_000_000], [$others[0], 20, 2_000_000], [$others[1], 30, 3_000_000], [$others[2], 40, 4_000_000], [$others[3], 50, 5_000_000], [$others[4], 60, 6_000_000]]);
    widgetInsight(['headline' => 'تازه‌ترین']);

    $r = $this->actingAs(Fx::userWith('customers.view', 'segments.view', 'ai.view'))->getJson("/customers/{$me->id}/analyst-panel")->assertOk();

    expect($r->json('segments'))->toHaveCount(1)->and($r->json('segments.0.name'))->toBe('وفادار')->and($r->json('segments.0.members'))->toBe(6)
        ->and($r->json('segments.0.avg_recency_days'))->toBe(35)->and($r->json('segments.0.avg_monetary'))->toBe(3_500_000)
        ->and($r->json('latest_insight.headline'))->toBe('تازه‌ترین')->and($r->json('latest_insight.created_at'))->toMatch('#^\d{4}/\d{2}/\d{2} #')
        ->and($r->getContent())->not->toContain('Zahra')->not->toContain('Distinct-Surname')->not->toContain('9123456789')->not->toContain('1000000');
});

it('shows a small segment\'s count but no averages (a tiny group\'s average could identify a person)', function () {
    $me = Customer::factory()->create();
    panelSegment('کوچک', 4, [[$me, 10, 1_000_000]]);

    $r = $this->actingAs(Fx::userWith('customers.view', 'segments.view'))->getJson("/customers/{$me->id}/analyst-panel")->assertOk();

    expect($r->json('segments.0.members'))->toBe(4)->and($r->json('segments.0.avg_recency_days'))->toBeNull()->and($r->json('segments.0.avg_monetary'))->toBeNull();
});

it('hides each part the viewer has no permission for (null, not empty) and omits inactive or deleted segments', function () {
    $me = Customer::factory()->create();
    panelSegment('فعال', 9, [[$me, 1, 1]]);
    $inactive = panelSegment('غیرفعال', 9, [[$me, 1, 1]]);
    $inactive->forceFill(['is_active' => false])->save();
    $deleted = panelSegment('حذف‌شده', 9, [[$me, 1, 1]]);
    $deleted->delete();
    widgetInsight();

    $plain = $this->actingAs(Fx::userWith('customers.view'))->getJson("/customers/{$me->id}/analyst-panel")->assertOk();
    $full = $this->actingAs(Fx::userWith('customers.view', 'segments.view', 'ai.view'))->getJson("/customers/{$me->id}/analyst-panel")->assertOk();

    expect($plain->json('segments'))->toBeNull()->and($plain->json('latest_insight'))->toBeNull()
        ->and(array_column($full->json('segments'), 'name'))->toBe(['فعال'])->and($full->json('latest_insight.id'))->toBeInt();
});

it('404s an unknown or soft-deleted customer, makes no AI call and queues nothing', function () {
    $gone = Customer::factory()->create();
    $gone->delete();
    $user = Fx::userWith('customers.view', 'segments.view', 'ai.view');

    $this->actingAs($user)->getJson('/customers/999999/analyst-panel')->assertNotFound();
    $this->actingAs($user)->getJson("/customers/{$gone->id}/analyst-panel")->assertNotFound();
    Queue::assertNothingPushed();
});
