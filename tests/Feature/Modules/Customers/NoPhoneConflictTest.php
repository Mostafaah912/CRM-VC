<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Customers\Services\CustomerIdentityService;

/*
| The Customers side of the GATE 1 blocker: an order with no usable phone has no customer to hang a conflict on, so the
| conflict is recorded against the ORDER — once, pending, nameless — through Customers' own service (Orders never writes
| identity_conflicts). It creates no customer and no identity.
*/

it('records one pending no_phone conflict for an order, with no customer, no names and no identity', function () {
    app(CustomerIdentityService::class)->recordOrderWithoutPhone(15091);

    $conflict = IdentityConflict::sole();
    expect($conflict->reason)->toBe(CustomerIdentityService::REASON_NO_PHONE)->toBe('no_phone')
        ->and($conflict->status->value)->toBe('pending')
        ->and($conflict->woo_order_id)->toBe(15091)
        ->and($conflict->customer_id)->toBeNull()
        ->and($conflict->existing_name)->toBeNull()
        ->and($conflict->incoming_name)->toBeNull()
        ->and(Customer::withTrashed()->count())->toBe(0)
        ->and(DB::table('customer_identities')->count())->toBe(0);
});

it('records it once per order, however often it is asked, and once for each different order', function () {
    $service = app(CustomerIdentityService::class);

    $service->recordOrderWithoutPhone(15091);
    $service->recordOrderWithoutPhone(15091);
    $service->recordOrderWithoutPhone(15092);
    $service->recordOrderWithoutPhone(15091);

    expect(IdentityConflict::orderBy('woo_order_id')->pluck('woo_order_id')->all())->toBe([15091, 15092]);
});

it('does not raise it again once a reviewer has dealt with it', function () {
    $service = app(CustomerIdentityService::class);
    $service->recordOrderWithoutPhone(15091);
    IdentityConflict::sole()->update(['status' => 'ignored']);

    $service->recordOrderWithoutPhone(15091);

    expect(IdentityConflict::count())->toBe(1)->and(IdentityConflict::sole()->status->value)->toBe('ignored');
});

it('leaves the name-mismatch conflicts of a customer alone', function () {
    $customer = Customer::factory()->create();
    IdentityConflict::create(['customer_id' => $customer->id, 'existing_name' => 'A', 'incoming_name' => 'B', 'woo_order_id' => 15091, 'reason' => 'last_name_mismatch', 'status' => 'pending']);

    app(CustomerIdentityService::class)->recordOrderWithoutPhone(15091);

    expect(IdentityConflict::count())->toBe(2)->and(IdentityConflict::where('reason', 'last_name_mismatch')->count())->toBe(1);
});
