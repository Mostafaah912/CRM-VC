<?php

declare(strict_types=1);

use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Segments\Enums\SegmentType;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\RuleCompiler;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultSegmentSeeder;
use Database\Seeders\DemoDataSeeder;

/*
| P5-07/P5-07b: all 12 of PRD §17's seed segments. Idempotent by lower(name); a soft-deleted seed
| segment is restored, not duplicated; a non-system segment with a colliding name is left untouched.
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

it('creates exactly the 12 defined segments, all is_system, dynamic, rule_version 1', function () {
    runSeeder();

    $segments = Segment::query()->get();

    expect($segments)->toHaveCount(12)
        ->and($segments->pluck('name')->unique())->toHaveCount(12)
        ->and($segments->every(fn (Segment $s) => $s->is_system === true))->toBeTrue()
        ->and($segments->every(fn (Segment $s) => $s->type->value === 'dynamic'))->toBeTrue()
        ->and($segments->every(fn (Segment $s) => $s->rule_version === 1))->toBeTrue()
        ->and($segments->every(fn (Segment $s) => $s->created_by === null))->toBeTrue();
});

it('is idempotent: running twice creates no duplicates and keeps the same ids', function () {
    runSeeder();
    $firstIds = Segment::query()->orderBy('id')->pluck('id', 'name');

    runSeeder();

    expect(Segment::query()->count())->toBe(12)
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
        ->and(Segment::query()->count())->toBe(12);
});

it('updates an existing is_system segment\'s rule in place on re-run (e.g. a stale rule_version or definition)', function () {
    runSeeder();
    $segment = Segment::query()->where('name', 'در معرض ریزش')->firstOrFail();
    $segment->forceFill(['rule' => ['field' => 'total_orders', 'operator' => '=', 'value' => 999]])->save();

    runSeeder();

    $updated = Segment::query()->where('name', 'در معرض ریزش')->firstOrFail();
    expect($updated->id)->toBe($segment->id)
        ->and($updated->rule)->toEqual(['field' => 'churn_risk_level', 'operator' => 'in', 'value' => ['medium', 'high']]);
});

it('never overwrites or converts a non-system segment that happens to share a seed name', function () {
    $user = Segment::query()->create([
        'name' => 'قهرمانان',
        'description' => 'سگمنت دستیِ خودم',
        'type' => SegmentType::Static,
        'rule' => ['field' => 'total_orders', 'operator' => '=', 'value' => 42],
        'rule_version' => 1,
        'is_active' => true,
        'is_system' => false,
        'created_by' => null,
    ]);

    runSeeder();

    $unchanged = Segment::query()->findOrFail($user->id);
    expect($unchanged->is_system)->toBeFalse()
        ->and($unchanged->description)->toBe('سگمنت دستیِ خودم')
        ->and($unchanged->rule)->toEqual(['field' => 'total_orders', 'operator' => '=', 'value' => 42])
        ->and(Segment::query()->where('name', 'قهرمانان')->count())->toBe(1);
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

    // "سررسید خرید مجدد" (within_days_of_now) recomputes its window from the real current instant
    // (P5-07b) — pin "now" to the fixture's own frozen AS_OF so its ±7d window lines up with the
    // expected_next_order_at values the fixture was generated against, not whatever day the test runs.
    Carbon\Carbon::setTestNow(DemoDataSeeder::asOf());

    $expected = segSeederExpectedMetrics();
    $customers = $expected['customers'];

    $expectedByName = [
        'قهرمانان' => $expected['store']['rfm_segment_counts']['champion'] ?? 0,
        'وفادار' => $expected['store']['rfm_segment_counts']['loyal'] ?? 0,
        'نویدبخش' => $expected['store']['rfm_segment_counts']['promising'] ?? 0,
        'مشتری جدید' => $expected['store']['rfm_segment_counts']['new_customer'] ?? 0,
        'در معرض ریزش' => ($expected['store']['churn_level_counts']['medium'] ?? 0) + ($expected['store']['churn_level_counts']['high'] ?? 0),
        'نباید از دست برود' => $expected['store']['rfm_segment_counts']['cant_lose'] ?? 0,
        'خوابیده' => $expected['store']['rfm_segment_counts']['hibernating'] ?? 0,
        'از دست رفته' => $expected['store']['churn_level_counts']['lost'] ?? 0,
        'تک‌خرید' => count(array_filter($customers, fn (array $c) => $c['total_orders'] === 1)),
        'پرارزش' => count(array_filter($customers, fn (array $c) => $c['m_score'] === 5)),
        'VIP' => count(array_filter($customers, fn (array $c) => $c['m_score'] === 5 && $c['f_score'] >= 4)),
        'سررسید خرید مجدد' => count(array_filter($customers, function (array $c) {
            if (! isset($c['expected_next_order_at'])) {
                return false;
            }

            $date = CarbonImmutable::parse($c['expected_next_order_at']);
            $now = DemoDataSeeder::asOf();

            return $date->greaterThanOrEqualTo($now->subDays(7)) && $date->lessThanOrEqualTo($now->addDays(7));
        })),
    ];

    foreach (Segment::query()->get() as $segment) {
        $count = RuleCompiler::compile($segment->rule)->count();

        expect($count)->toBe($expectedByName[$segment->name], "member count for «{$segment->name}»");
    }

    Carbon\Carbon::setTestNow();
});
