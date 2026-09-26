<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Jobs\EvaluateSegmentJob;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Services\SegmentService;
use Illuminate\Support\Facades\DB;

/*
| P5-06: EvaluateSegmentJob is a thin wrapper — handle() must call SegmentService::evaluateById(),
| which does the lookup-by-id itself (a Job may never import a module Model, tests/Arch/ArchitectureTest.php).
*/

it('evaluates the segment by id and writes its members', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 5]);
    $segment = Segment::factory()->create(['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]]);

    (new EvaluateSegmentJob($segment->id))->handle(app(SegmentService::class));

    expect($segment->refresh()->member_count)->toBe(1)
        ->and(DB::table('segment_members')->where('segment_id', $segment->id)->where('customer_id', $customer->id)->exists())->toBeTrue();
});

it('is uniquely identified by its segment id', function () {
    $job = new EvaluateSegmentJob(42);

    expect($job->uniqueId())->toBe('42');
});
