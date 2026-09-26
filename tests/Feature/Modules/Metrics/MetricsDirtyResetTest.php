<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| P6-bugfix (TEST FIRST): PRD §11 step 11 ("metrics_dirty = false") never actually ran —
| BaseAggregateService's own docblock claimed P4-07 cleared the flag once a customer's full
| pipeline reran, but MetricsRecomputeService::run() never wrote it (ARCHITECTURE.md Open Items,
| found P5-08). Confirmed on dev: all 19,905 customers stayed metrics_dirty=true forever, so
| `metrics:recompute --dirty` never actually narrowed anything.
*/

function latestRun(): object
{
    return DB::table('metric_runs')->latest('id')->first();
}

it('resets metrics_dirty to false for customers a dirty run actually processes', function () {
    $customer = Customer::factory()->create(['metrics_dirty' => true]);
    Order::factory()->for($customer)->create(['is_realized' => true]);

    RecomputeMetricsJob::dispatchSync('dirty');

    expect($customer->refresh()->metrics_dirty)->toBeFalse();
});

it('processes zero customers on a second dirty run once nothing is left dirty', function () {
    $customer = Customer::factory()->create(['metrics_dirty' => true]);
    Order::factory()->for($customer)->create(['is_realized' => true]);

    RecomputeMetricsJob::dispatchSync('dirty');
    expect($customer->refresh()->metrics_dirty)->toBeFalse();

    RecomputeMetricsJob::dispatchSync('dirty');

    expect(latestRun()->customers_processed)->toBe(0);
});

it('resets metrics_dirty for every processed customer on a full run, regardless of prior state', function () {
    $wasDirty = Customer::factory()->create(['metrics_dirty' => true]);
    $wasClean = Customer::factory()->create(['metrics_dirty' => false]);
    Order::factory()->for($wasDirty)->create(['is_realized' => true]);
    Order::factory()->for($wasClean)->create(['is_realized' => true]);

    RecomputeMetricsJob::dispatchSync('full');

    expect($wasDirty->refresh()->metrics_dirty)->toBeFalse()
        ->and($wasClean->refresh()->metrics_dirty)->toBeFalse();
});

it('leaves a soft-deleted customer, which the pipeline never touches, dirty', function () {
    $customer = Customer::factory()->create(['metrics_dirty' => true]);
    $customer->delete();

    RecomputeMetricsJob::dispatchSync('full');

    expect(DB::table('customers')->where('id', $customer->id)->value('metrics_dirty'))->toBeTrue();
});

it('does not reset metrics_dirty when the pipeline fails mid-run: the reset is inside the same transaction', function () {
    $customer = Customer::factory()->create(['metrics_dirty' => true]);
    Order::factory()->for($customer)->create(['is_realized' => true]);

    // Same technique as RecomputeMetricsJobTest: a real Postgres error after base aggregates
    // (which already wrote this customer's metric_run_id) but before the pipeline finishes.
    DB::statement('ALTER TABLE customer_metrics DROP COLUMN purchase_cycle_days');

    expect(fn () => RecomputeMetricsJob::dispatchSync('dirty'))->toThrow(QueryException::class);

    expect($customer->refresh()->metrics_dirty)->toBeTrue();
});
