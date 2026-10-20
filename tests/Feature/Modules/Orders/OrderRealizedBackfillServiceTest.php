<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Events\OrderSynced;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderRealizedBackfillService;
use App\Modules\Orders\Services\OrderStatusMapper;
use Illuminate\Support\Facades\Event;

/*
| P7-14 — `orders.is_realized` is STORED at write time from config('woo.realized_statuses'); when the list changes the existing rows keep the
| old flag. The backfill re-derives it locally, both directions, idempotently, and marks customers dirty through the ordinary OrderSynced event.
*/

function orderWith(string $status, bool $flag, ?Customer $customer = null): Order
{
    return Order::factory()->create(['status' => $status, 'is_realized' => $flag, 'customer_id' => ($customer ?? Customer::factory()->create())->id]);
}

it('makes existing taroff-4 orders realized once the status is in the config, and leaves every other row alone', function () {
    $unbox = orderWith('taroff-4', false);
    $done = orderWith('completed', true);
    $cancelled = orderWith('cancelled', false);
    $refunded = orderWith('refunded', false);

    $result = app(OrderRealizedBackfillService::class)->reclassify();

    expect($result->madeRealized)->toBe(1)->and($result->madeNotRealized)->toBe(0)->and($result->total())->toBe(1)
        ->and($unbox->fresh()->is_realized)->toBeTrue()->and($done->fresh()->is_realized)->toBeTrue()
        ->and($cancelled->fresh()->is_realized)->toBeFalse()->and($refunded->fresh()->is_realized)->toBeFalse();
});

it('is idempotent: a second run changes nothing', function () {
    orderWith('taroff-4', false);

    $first = app(OrderRealizedBackfillService::class)->reclassify();
    $second = app(OrderRealizedBackfillService::class)->reclassify();

    expect($first->total())->toBe(1)->and($second->total())->toBe(0);
});

it('works in both directions: a status removed from the config stops counting', function () {
    $unbox = orderWith('taroff-4', true);
    config(['woo.realized_statuses' => ['processing', 'completed']]);

    $result = app(OrderRealizedBackfillService::class)->reclassify();

    expect($result->madeNotRealized)->toBe(1)->and($unbox->fresh()->is_realized)->toBeFalse();
});

it('follows whatever the config says — nothing is hardcoded in the service', function () {
    $a = orderWith('shipped', false);
    config(['woo.realized_statuses' => ['shipped']]);

    app(OrderRealizedBackfillService::class)->reclassify();

    expect($a->fresh()->is_realized)->toBeTrue();
});

it('covers soft-deleted orders too and processes more rows than one chunk', function () {
    $customer = Customer::factory()->create();
    $trashed = orderWith('taroff-4', false, $customer);
    $trashed->delete();
    Order::factory()->count(520)->create(['status' => 'taroff-4', 'is_realized' => false, 'customer_id' => $customer->id]);

    $result = app(OrderRealizedBackfillService::class)->reclassify();

    expect($result->madeRealized)->toBe(521)->and(Order::withTrashed()->where('status', 'taroff-4')->where('is_realized', false)->count())->toBe(0);
});

it('dispatches OrderSynced for every changed order (so Metrics marks the customer dirty) and for no unchanged one', function () {
    Event::fake([OrderSynced::class]);
    $changed = orderWith('taroff-4', false);
    orderWith('completed', true);

    app(OrderRealizedBackfillService::class)->reclassify();

    Event::assertDispatchedTimes(OrderSynced::class, 1);
    Event::assertDispatched(OrderSynced::class, fn ($e) => $e->orderId === $changed->id && $e->customerId === $changed->customer_id);
});

it('hm:orders-reclassify prints what it changed and is idempotent', function () {
    orderWith('taroff-4', false);

    $this->artisan('hm:orders-reclassify')->expectsOutput('made realized: 1, made not realized: 0')->assertSuccessful();
    $this->artisan('hm:orders-reclassify')->expectsOutput('made realized: 0, made not realized: 0')->assertSuccessful();
});

it('the order sync path classifies a new taroff-4 order as realized from the config alone', function () {
    expect(app(OrderStatusMapper::class)->isRealized('taroff-4'))->toBeTrue()
        ->and(config('woo.realized_statuses'))->toContain('taroff-4');
});
