<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Support\Facades\DB;

/*
| P5-08: SegmentService::rebuildAll() — RebuildAllSegmentsJob's entry point. Every active dynamic
| segment is (re)evaluated; static/manual, inactive, and soft-deleted segments are skipped; one
| segment's failure never stops the rest of the batch.
*/

function matchingCustomer(): Customer
{
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 5]);

    return $customer;
}

it('evaluates every active dynamic segment', function () {
    $customer = matchingCustomer();
    $a = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    $b = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    $summary = app(SegmentService::class)->rebuildAll();

    expect($summary->succeededSegmentIds)->toEqualCanonicalizing([$a->id, $b->id])
        ->and($summary->failedSegmentIds)->toBe([])
        ->and($a->refresh()->member_count)->toBe(1)
        ->and($b->refresh()->member_count)->toBe(1)
        ->and(DB::table('segment_members')->where('segment_id', $a->id)->where('customer_id', $customer->id)->exists())->toBeTrue();
});

it('skips static and manual segments — they have no rule to evaluate', function () {
    matchingCustomer();
    $static = Segment::factory()->static()->create();
    $manual = Segment::factory()->manual()->create();
    $dynamic = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    $summary = app(SegmentService::class)->rebuildAll();

    expect($summary->succeededSegmentIds)->toBe([$dynamic->id])
        ->and($static->refresh()->last_evaluated_at)->toBeNull()
        ->and($manual->refresh()->last_evaluated_at)->toBeNull();
});

it('skips an inactive segment', function () {
    matchingCustomer();
    $inactive = Segment::factory()->create(['is_active' => false, 'rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    $active = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    $summary = app(SegmentService::class)->rebuildAll();

    expect($summary->succeededSegmentIds)->toBe([$active->id])
        ->and($inactive->refresh()->last_evaluated_at)->toBeNull();
});

it('skips a soft-deleted segment', function () {
    matchingCustomer();
    $deleted = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    $deleted->delete();
    $active = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    $summary = app(SegmentService::class)->rebuildAll();

    expect($summary->succeededSegmentIds)->toBe([$active->id]);
});

it('logs one segment\'s failure and continues evaluating the rest of the batch', function () {
    matchingCustomer();
    $broken = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);
    // Bypass RuleValidator (as create()/modify() would enforce) to simulate a rule that only fails
    // at evaluate-time — e.g. one written by a since-removed whitelist entry — without corrupting the
    // Segment factory's own valid-by-construction contract used by every other test in this suite.
    $broken->forceFill(['rule' => ['field' => 'not_a_whitelisted_field', 'operator' => '=', 'value' => 1]])->save();
    $healthy = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    $summary = app(SegmentService::class)->rebuildAll();

    expect($summary->succeededSegmentIds)->toBe([$healthy->id])
        ->and($summary->failedSegmentIds)->toHaveKey($broken->id)
        ->and($healthy->refresh()->member_count)->toBe(1)
        ->and($broken->refresh()->member_count)->toBe(0); // untouched by the failed attempt, not corrupted
});

it('reports zero succeeded and zero failed when there is nothing to rebuild', function () {
    $summary = app(SegmentService::class)->rebuildAll();

    expect($summary->succeededSegmentIds)->toBe([])
        ->and($summary->failedSegmentIds)->toBe([])
        ->and($summary->elapsedMs)->toBeGreaterThanOrEqual(0);
});
