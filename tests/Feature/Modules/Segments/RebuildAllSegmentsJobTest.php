<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Jobs\RebuildAllSegmentsJob;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Support\Facades\DB;

/*
| P5-08: RebuildAllSegmentsJob is a thin wrapper — handle() must call SegmentService::rebuildAll(),
| which does the query/loop/per-segment isolation itself (a Job may never import a module Model,
| tests/Arch/ArchitectureTest.php).
*/

it('rebuilds every active dynamic segment\'s members', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 5]);
    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    (new RebuildAllSegmentsJob)->handle(app(SegmentService::class));

    expect($segment->refresh()->member_count)->toBe(1)
        ->and(DB::table('segment_members')->where('segment_id', $segment->id)->where('customer_id', $customer->id)->exists())->toBeTrue();
});

it('is uniquely identified by a fixed string — at most one rebuild runs at a time', function () {
    expect((new RebuildAllSegmentsJob)->uniqueId())->toBe('rebuild-all-segments');
});

it('sets uniqueFor greater than timeout, as PRD §22 requires for every whole-data job', function () {
    $job = new RebuildAllSegmentsJob;

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('is queued on the metrics queue, behind RecomputeMetricsJob(\'full\') in PRD §22\'s nightly chain', function () {
    expect((new RebuildAllSegmentsJob)->queue)->toBe('metrics');
});
