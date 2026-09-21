<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Listeners;

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Orders\Events\OrderSynced;

/**
 * PRD §11 "hourly on dirty" cadence: a synced order marks its customer dirty and schedules a delayed
 * 'dirty' recompute. The 5-minute delay plus RecomputeMetricsJob's own ShouldBeUnique is the batching
 * mechanism — ten orders for the same (or different) customers within that window still dispatch ten
 * jobs, but only the first actually runs; the rest are dropped as duplicates of the still-pending one.
 *
 * An order that never resolved a customer (needs_phone_review) has nothing to mark dirty.
 */
final class MarkCustomerMetricsDirty
{
    public function handle(OrderSynced $event): void
    {
        if ($event->customerId === null) {
            return;
        }

        Customer::where('id', $event->customerId)->update(['metrics_dirty' => true]);

        RecomputeMetricsJob::dispatch('dirty')->delay(now()->addMinutes(5));
    }
}
