<?php

declare(strict_types=1);

use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\RuleCompiler;
use Database\Seeders\DefaultSegmentSeeder;
use Database\Seeders\DemoDataSeeder;

/*
| P5-07: PRD §17's seed segments — 11 built (the 12th, "سررسید خرید مجدد", is deferred; see
| ARCHITECTURE.md). Idempotent by lower(name); a soft-deleted seed segment is restored, not duplicated.
*/

function runSeeder(): void
{
    app(DefaultSegmentSeeder::class)->run();
}

/** @return array<string, int> phone => n, for the fixture's customer rows */
function segSeederExpectedMetrics(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/expected_metrics.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('creates exactly the 11 defined segments, all is_system, dynamic, rule_version 1', function () {
    runSeeder();

    $segments = Segment::query()->get();

    expect($segments)->toHaveCount(11)
        ->and($segments->pluck('name')->unique())->toHaveCount(11)
        ->and($segments->every(fn (Segment $s) => $s->is_system === true))->toBeTrue()
        ->and($segments->every(fn (Segment $s) => $s->type->value === 'dynamic'))->toBeTrue()
        ->and($segments->every(fn (Segment $s) => $s->rule_version === 1))->toBeTrue()
        ->and($segments->every(fn (Segment $s) => $s->created_by === null))->toBeTrue();
});

it('is idempotent: running twice creates no duplicates and keeps the same ids', function () {
    runSeeder();
    $firstIds = Segment::query()->orderBy('id')->pluck('id', 'name');

    runSeeder();

    expect(Segment::query()->count())->toBe(11)
        ->and(Segment::query()->orderBy('id')->pluck('id', 'name')->all())->toBe($firstIds->all());
});

it('restores a soft-deleted seed segment on re-run, without creating a duplicate', function () {
    runSeeder();
    $segment = Segment::query()->where('name', 'قهرمانان')->firstOrFail();
    $segment->delete();
    expect(Segment::withTrashed()->find($segment->id)->trashed())->toBeTrue();

    runSeeder();

    $restored = Segment::query()->where('name', 'قهرمانان')->first();
    expect($restored)->not->toBeNull()
        ->and($restored->id)->toBe($segment->id)
        ->and($restored->is_system)->toBeTrue()
        ->and(Segment::query()->count())->toBe(11);
});

it('keeps every definition passing RuleValidator and RuleCompiler (real Postgres, no error)', function () {
    foreach (DefaultSegmentSeeder::definitions() as $definition) {
        expect(fn () => RuleCompiler::compile($definition['rule'])->count())->not->toThrow(Throwable::class);
    }
});

it('matches expected_metrics.json member counts for every rfm_segment/churn_risk_level/metric-based seed', function () {
    app(DemoDataSeeder::class)->run();
    RecomputeMetricsJob::dispatchSync('full', DemoDataSeeder::asOf());
    runSeeder();

    $expected = segSeederExpectedMetrics();
    $customers = $expected['customers'];

    $expectedByName = [
        'قهرمانان' => $expected['store']['rfm_segment_counts']['champion'] ?? 0,
        'وفادار' => $expected['store']['rfm_segment_counts']['loyal'] ?? 0,
        'نویدبخش' => $expected['store']['rfm_segment_counts']['promising'] ?? 0,
        'مشتری جدید' => $expected['store']['rfm_segment_counts']['new_customer'] ?? 0,
        'در معرض ریزش' => $expected['store']['churn_level_counts']['medium'] ?? 0,
        'نباید از دست برود' => $expected['store']['rfm_segment_counts']['cant_lose'] ?? 0,
        'خوابیده' => $expected['store']['rfm_segment_counts']['hibernating'] ?? 0,
        'از دست رفته' => $expected['store']['churn_level_counts']['lost'] ?? 0,
        'تک‌خرید' => count(array_filter($customers, fn (array $c) => $c['total_orders'] === 1)),
        'پرارزش' => count(array_filter($customers, fn (array $c) => $c['m_score'] === 5)),
        'VIP' => count(array_filter($customers, fn (array $c) => $c['m_score'] === 5 && $c['f_score'] >= 4)),
    ];

    foreach (Segment::query()->get() as $segment) {
        $count = RuleCompiler::compile($segment->rule)->count();

        expect($count)->toBe($expectedByName[$segment->name], "member count for «{$segment->name}»");
    }
});
