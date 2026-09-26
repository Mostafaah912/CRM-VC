<?php

declare(strict_types=1);

use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Segments\Events\CustomerEnteredSegment;
use App\Modules\Segments\Events\CustomerLeftSegment;
use App\Modules\Segments\Events\SegmentEvaluated;
use App\Modules\Segments\Exceptions\RuleValidationException;
use App\Modules\Segments\Exceptions\SegmentException;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\SegmentService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| PRD §17 "ارزیابی": evaluate() = compile -> pluck ids -> transaction (DELETE; bulk INSERT) ->
| UPDATE member_count/last_evaluated_at/last_eval_ms -> diff -> CustomerEntered/LeftSegment.
| Real PostgreSQL, real DemoDataSeeder data (50 customers) + a real RecomputeMetricsJob run so
| customer_metrics actually has scores to filter on, not fixture-free zeros.
*/

function seedDemoWithMetrics(): void
{
    app(DemoDataSeeder::class)->run();
    RecomputeMetricsJob::dispatchSync('full', DemoDataSeeder::asOf());
}

it('populates segment_members with exactly the customers matching the rule', function () {
    seedDemoWithMetrics();

    $expectedCount = DB::table('customer_metrics')->where('total_orders', '>=', 1)->count();
    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    $result = app(SegmentService::class)->evaluate($segment);

    expect($result->memberCount)->toBe($expectedCount)
        ->and($result->segmentId)->toBe($segment->id)
        ->and(DB::table('segment_members')->where('segment_id', $segment->id)->count())->toBe($expectedCount);
});

it('reports every matched customer as entered on the first evaluation', function () {
    seedDemoWithMetrics();

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    $result = app(SegmentService::class)->evaluate($segment);

    expect($result->enteredCount)->toBe($result->memberCount)
        ->and($result->leftCount)->toBe(0);
});

it('is idempotent: evaluating twice with unchanged data reports zero entered/left the second time', function () {
    seedDemoWithMetrics();

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    $service = app(SegmentService::class);

    $first = $service->evaluate($segment);
    $second = $service->evaluate($segment->fresh());

    expect($second->memberCount)->toBe($first->memberCount)
        ->and($second->enteredCount)->toBe(0)
        ->and($second->leftCount)->toBe(0);
});

it('reports left customers when a tightened rule drops members on re-evaluation', function () {
    seedDemoWithMetrics();

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    $service = app(SegmentService::class);
    $service->evaluate($segment);

    $tighterCount = DB::table('customer_metrics')->where('total_orders', '>=', 3)->count();
    $segment->update(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 3]]);

    $result = $service->evaluate($segment->fresh());

    expect($result->memberCount)->toBe($tighterCount)
        ->and($result->leftCount)->toBeGreaterThan(0)
        ->and($result->enteredCount)->toBe(0);
});

it('updates member_count, last_evaluated_at and last_eval_ms on the segment row', function () {
    seedDemoWithMetrics();

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    app(SegmentService::class)->evaluate($segment);
    $fresh = $segment->fresh();

    expect($fresh->member_count)->toBeGreaterThan(0)
        ->and($fresh->last_evaluated_at)->not->toBeNull()
        ->and($fresh->last_eval_ms)->toBeGreaterThanOrEqual(0);
});

it('dispatches CustomerEnteredSegment, CustomerLeftSegment and SegmentEvaluated', function () {
    Event::fake([CustomerEnteredSegment::class, CustomerLeftSegment::class, SegmentEvaluated::class]);
    seedDemoWithMetrics();

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    $expectedCount = DB::table('customer_metrics')->where('total_orders', '>=', 1)->count();

    app(SegmentService::class)->evaluate($segment);

    Event::assertDispatchedTimes(CustomerEnteredSegment::class, $expectedCount);
    Event::assertNotDispatched(CustomerLeftSegment::class);
    Event::assertDispatched(SegmentEvaluated::class, fn (SegmentEvaluated $e): bool => $e->segmentId === $segment->id
        && $e->memberCount === $expectedCount
        && $e->enteredCount === $expectedCount
        && $e->leftCount === 0);
});

it('refuses to evaluate a static segment', function () {
    $segment = Segment::factory()->static()->create();

    expect(fn () => app(SegmentService::class)->evaluate($segment))
        ->toThrow(SegmentException::class);

    try {
        app(SegmentService::class)->evaluate($segment);
    } catch (SegmentException $e) {
        expect($e->reason)->toBe(SegmentException::NOT_DYNAMIC);
    }
});

it('refuses to evaluate a manual segment', function () {
    $segment = Segment::factory()->manual()->create();

    expect(fn () => app(SegmentService::class)->evaluate($segment))
        ->toThrow(SegmentException::class);
});

it('rejects a segment whose rule fails validation, before touching segment_members', function () {
    $segment = Segment::factory()->create(['rule' => ['field' => 'email', 'operator' => '=', 'value' => 'x']]);

    expect(fn () => app(SegmentService::class)->evaluate($segment))
        ->toThrow(RuleValidationException::class);

    expect(DB::table('segment_members')->where('segment_id', $segment->id)->count())->toBe(0);
});

it('rolls back the whole membership swap when the insert step fails, keeping prior members intact', function () {
    seedDemoWithMetrics();

    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    $service = app(SegmentService::class);
    $service->evaluate($segment);

    $beforeCount = DB::table('segment_members')->where('segment_id', $segment->id)->count();
    expect($beforeCount)->toBeGreaterThan(0);

    DB::statement('ALTER TABLE segment_members DROP COLUMN added_at');

    expect(fn () => $service->evaluate($segment->fresh()))->toThrow(QueryException::class);

    expect(DB::table('segment_members')->where('segment_id', $segment->id)->count())->toBe($beforeCount);
});
