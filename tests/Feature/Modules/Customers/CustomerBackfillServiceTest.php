<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Customers\Services\CustomerBackfillService;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

/*
| P6-14 phase 4 (TEST FIRST): two local-only backfills, no Woo call. 0/20,150 customers had
| province/city/first_seen_at and 0 had needs_review=true on dev before this task, despite
| 240+ pending conflicts and plenty of locally-synced order history existing already.
*/

function backfillOrder(Customer $customer, string $orderedAt): Order
{
    return Order::factory()->for($customer)->create(['ordered_at' => CarbonImmutable::parse($orderedAt, 'UTC')]);
}

it('sets first_seen_at to the oldest local order for a customer who has none yet', function () {
    $customer = Customer::factory()->create(['first_seen_at' => null]);
    backfillOrder($customer, '2026-03-10 10:00:00');
    backfillOrder($customer, '2026-01-05 08:00:00');
    backfillOrder($customer, '2026-02-20 09:00:00');

    $moved = app(CustomerBackfillService::class)->backfillFirstSeenAtFromLocalOrders();

    expect($moved)->toBe(1)
        ->and($customer->fresh()->first_seen_at?->equalTo(CarbonImmutable::parse('2026-01-05 08:00:00', 'UTC')))->toBeTrue();
});

it('moves an existing first_seen_at EARLIER when an older local order is found, never later', function () {
    $customer = Customer::factory()->create(['first_seen_at' => CarbonImmutable::parse('2026-02-01', 'UTC')]);
    backfillOrder($customer, '2026-01-01 00:00:00'); // older than stored

    app(CustomerBackfillService::class)->backfillFirstSeenAtFromLocalOrders();

    expect($customer->fresh()->first_seen_at?->equalTo(CarbonImmutable::parse('2026-01-01', 'UTC')))->toBeTrue();
});

it('never moves first_seen_at later: a customer whose stored value is already older than any local order is untouched', function () {
    $alreadyOlder = CarbonImmutable::parse('2025-01-01', 'UTC');
    $customer = Customer::factory()->create(['first_seen_at' => $alreadyOlder]);
    backfillOrder($customer, '2026-05-01 00:00:00'); // newer than stored

    $moved = app(CustomerBackfillService::class)->backfillFirstSeenAtFromLocalOrders();

    expect($moved)->toBe(0)
        ->and($customer->fresh()->first_seen_at?->equalTo($alreadyOlder))->toBeTrue();
});

it('ignores orders with no customer_id and soft-deleted orders', function () {
    $customer = Customer::factory()->create(['first_seen_at' => null]);
    Order::factory()->create(['customer_id' => null, 'needs_phone_review' => true, 'ordered_at' => CarbonImmutable::parse('2020-01-01', 'UTC')]);
    $deleted = backfillOrder($customer, '2020-06-01 00:00:00');
    $deleted->delete();
    backfillOrder($customer, '2026-01-01 00:00:00');

    app(CustomerBackfillService::class)->backfillFirstSeenAtFromLocalOrders();

    expect($customer->fresh()->first_seen_at?->equalTo(CarbonImmutable::parse('2026-01-01', 'UTC')))->toBeTrue();
});

it('is idempotent: a second run moves nothing further', function () {
    $customer = Customer::factory()->create(['first_seen_at' => null]);
    backfillOrder($customer, '2026-01-01 00:00:00');
    $service = app(CustomerBackfillService::class);

    $service->backfillFirstSeenAtFromLocalOrders();
    $second = $service->backfillFirstSeenAtFromLocalOrders();

    expect($second)->toBe(0);
});

it('flags needs_review true for every customer with a pending identity conflict', function () {
    $customer = Customer::factory()->create(['needs_review' => false]);
    IdentityConflict::create([
        'customer_id' => $customer->id, 'existing_name' => 'ایکس', 'incoming_name' => 'ایگرگ',
        'woo_order_id' => 9001, 'reason' => 'last_name_mismatch', 'status' => 'pending',
    ]);

    $flagged = app(CustomerBackfillService::class)->backfillNeedsReviewFromPendingConflicts();

    expect($flagged)->toBe(1)->and($customer->fresh()->needs_review)->toBeTrue();
});

it('does not flag a customer whose only conflict is already resolved', function () {
    $customer = Customer::factory()->create(['needs_review' => false]);
    IdentityConflict::create([
        'customer_id' => $customer->id, 'existing_name' => 'ایکس', 'incoming_name' => 'ایگرگ',
        'woo_order_id' => 9001, 'reason' => 'last_name_mismatch', 'status' => 'confirmed_same',
    ]);

    app(CustomerBackfillService::class)->backfillNeedsReviewFromPendingConflicts();

    expect($customer->fresh()->needs_review)->toBeFalse();
});

it('is idempotent: a second needs_review backfill flags nothing further', function () {
    $customer = Customer::factory()->create(['needs_review' => false]);
    IdentityConflict::create([
        'customer_id' => $customer->id, 'existing_name' => 'ایکس', 'incoming_name' => 'ایگرگ',
        'woo_order_id' => 9001, 'reason' => 'last_name_mismatch', 'status' => 'pending',
    ]);
    $service = app(CustomerBackfillService::class);

    $service->backfillNeedsReviewFromPendingConflicts();
    $second = $service->backfillNeedsReviewFromPendingConflicts();

    expect($second)->toBe(0);
});
