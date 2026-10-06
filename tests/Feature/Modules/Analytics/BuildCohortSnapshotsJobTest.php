<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\BuildCohortSnapshotsJob;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

/* P6-03 (PRD §22): a thin Job wrapper around CohortSnapshotService::rebuild(). */

it('implements ShouldBeUnique so at most one rebuild runs at a time', function () {
    expect(in_array(ShouldBeUnique::class, class_implements(BuildCohortSnapshotsJob::class), true))->toBeTrue();
});

it('sets uniqueFor greater than timeout, per PRD §22\'s rule for every whole-data job', function () {
    $job = new BuildCohortSnapshotsJob;

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('rebuilds cohort_snapshots when dispatched', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->updateOrInsert(
        ['customer_id' => $customer->id],
        ['cohort_month' => '1404-01'],
    );
    Order::factory()->for($customer)->create(['is_realized' => true, 'ordered_at' => now()]);

    BuildCohortSnapshotsJob::dispatchSync();

    expect(DB::table('cohort_snapshots')->count())->toBeGreaterThan(0);
});
