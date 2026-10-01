<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Metrics\Jobs\RecomputeMetricsJob;
use App\Modules\Metrics\Listeners\MarkCustomerMetricsDirty;
use App\Modules\Orders\Events\OrderSynced;
use App\Modules\Orders\Services\OrderInput;
use App\Modules\Orders\Services\OrderItemInput;
use App\Modules\Orders\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| P4-07: OrderSynced (fired from Orders\Services\OrderService::upsert after commit, same pattern as
| Catalog\Events\ProductSynced) marks its customer dirty and schedules a delayed 'dirty' recompute. An
| order with no resolvable customer (needs_phone_review, customerId null) has nothing to mark.
*/

it('sets metrics_dirty on the event\'s customer', function () {
    // Queue::fake(): QUEUE_CONNECTION=sync in testing runs a real dispatch() inline. Now that
    // MetricsRecomputeService actually resets metrics_dirty (Sprint 6 bugfix), an unfaked dispatch here
    // would immediately run RecomputeMetricsJob('dirty') and flip the flag this test is asserting back
    // to false before the assertion even runs — this test only cares about the Listener's own write.
    Queue::fake();
    $customer = Customer::factory()->create(['metrics_dirty' => false]);

    app(MarkCustomerMetricsDirty::class)->handle(new OrderSynced(orderId: 1, customerId: $customer->id));

    expect($customer->fresh()->metrics_dirty)->toBeTrue();
});

it('dispatches a delayed dirty RecomputeMetricsJob', function () {
    Queue::fake();
    $customer = Customer::factory()->create(['metrics_dirty' => false]);

    app(MarkCustomerMetricsDirty::class)->handle(new OrderSynced(orderId: 1, customerId: $customer->id));

    Queue::assertPushed(RecomputeMetricsJob::class, fn (RecomputeMetricsJob $job) => $job->runType === 'dirty');
});

it('never touches another customer\'s metrics_dirty', function () {
    // Same reason as the first test above: fake the queue so a real synchronous RecomputeMetricsJob
    // run never runs and resets these flags before the assertion.
    Queue::fake();
    $target = Customer::factory()->create(['metrics_dirty' => false]);
    $other = Customer::factory()->create(['metrics_dirty' => false]);

    app(MarkCustomerMetricsDirty::class)->handle(new OrderSynced(orderId: 1, customerId: $target->id));

    expect($target->fresh()->metrics_dirty)->toBeTrue()
        ->and($other->fresh()->metrics_dirty)->toBeFalse();
});

it('does nothing when the order has no resolvable customer', function () {
    Queue::fake();

    app(MarkCustomerMetricsDirty::class)->handle(new OrderSynced(orderId: 1, customerId: null));

    Queue::assertNotPushed(RecomputeMetricsJob::class);
});

/**
 * End-to-end wiring: a real OrderService::upsert() call, with the real event dispatcher and the real
 * listener registered in AppServiceProvider::boot() — nothing faked except the queue, so this proves
 * the whole chain (OrderService -> OrderSynced -> MarkCustomerMetricsDirty) is actually connected, not
 * just each piece in isolation. The order's item is left unresolved on purpose (no catalog seeded) —
 * OrderService never rejects an order over that, and this test only cares about the customer/event side.
 */
it('marks a real customer dirty and queues a dirty job when OrderService actually syncs an order', function () {
    Queue::fake();
    $at = fn (string $t) => CarbonImmutable::parse($t, 'UTC');

    $orderId = app(OrderService::class)->upsert(new OrderInput(
        wooOrderId: 5001, number: '5001', status: 'completed', wooCustomerId: 11,
        billingFirstName: 'مشتری', billingLastName: 'نمونه', billingPhone: '09000000101',
        total: 100_000, discountTotal: 0, shippingTotal: 0, taxTotal: 0,
        couponCodes: [], paymentMethod: 'synthetic_gateway',
        orderedAt: $at('2026-05-10 08:30:00'), paidAt: $at('2026-05-10 08:35:00'),
        completedAt: $at('2026-05-12 09:00:00'), wooModifiedAt: $at('2026-05-12 09:00:00'),
        items: [new OrderItemInput(
            wooItemId: 9001, wooProductId: null, wooVariationId: null, sku: null,
            name: 'کالای نمونه', quantity: 1, unitPrice: 100_000, lineSubtotal: 100_000, lineTotal: 100_000,
        )],
    ));

    $customerId = DB::table('orders')->where('id', $orderId)->value('customer_id');
    expect($customerId)->not->toBeNull()
        ->and(DB::table('customers')->where('id', $customerId)->value('metrics_dirty'))->toBeTrue();
    Queue::assertPushed(RecomputeMetricsJob::class, fn (RecomputeMetricsJob $job) => $job->runType === 'dirty');
});
