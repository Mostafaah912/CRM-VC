<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\BuildDailyMetricsJob;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

/* P6-02 (PRD §22): a thin Job wrapper around DailyMetricsService::rebuild(), called as BuildDailyMetricsJob(3). */

it('implements ShouldBeUnique so at most one rebuild runs at a time', function () {
    expect(in_array(ShouldBeUnique::class, class_implements(BuildDailyMetricsJob::class), true))->toBeTrue();
});

it('sets uniqueFor greater than timeout, per PRD §22\'s rule for every whole-data job', function () {
    $job = new BuildDailyMetricsJob;

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

it('defaults to a 3-day window, matching the scheduler chain\'s BuildDailyMetricsJob(3)', function () {
    expect((new BuildDailyMetricsJob)->days)->toBe(3);
});

it('rebuilds the daily_metrics table when dispatched', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create([
        'is_realized' => true,
        'total' => 250_000,
        'ordered_at' => CarbonImmutable::now(),
    ]);

    BuildDailyMetricsJob::dispatchSync(1);

    expect(DB::table('daily_metrics')->count())->toBeGreaterThanOrEqual(1);
});
