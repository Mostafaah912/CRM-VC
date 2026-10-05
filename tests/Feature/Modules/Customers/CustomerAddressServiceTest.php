<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerAddressService;
use Illuminate\Support\Facades\DB;

/*
| P6-14 phase 4: customer_addresses (PRD §09) — one row per (customer, type), upserted to the most
| recent non-empty values (PRD gives no uniqueness constraint; a row-per-order history was judged out
| of scope, see ARCHITECTURE.md "P6-17").
*/

it('inserts a new billing address row', function () {
    $customer = Customer::factory()->create();

    app(CustomerAddressService::class)->upsert($customer->id, 'billing', 'تهران', 'تهران', 'خیابان آزادی', '1234567890');

    $row = DB::table('customer_addresses')->where('customer_id', $customer->id)->where('type', 'billing')->sole();
    expect($row->province)->toBe('تهران')
        ->and($row->city)->toBe('تهران')
        ->and($row->address)->toBe('خیابان آزادی')
        ->and($row->postcode)->toBe('1234567890')
        ->and((bool) $row->is_default)->toBeTrue();
});

it('keeps billing and shipping as separate rows for the same customer', function () {
    $customer = Customer::factory()->create();
    $service = app(CustomerAddressService::class);

    $service->upsert($customer->id, 'billing', 'تهران', 'تهران', null, null);
    $service->upsert($customer->id, 'shipping', 'اصفهان', 'اصفهان', null, null);

    expect(DB::table('customer_addresses')->where('customer_id', $customer->id)->count())->toBe(2);
});

it('updates the same (customer, type) row on a second call, never inserting a second one', function () {
    $customer = Customer::factory()->create();
    $service = app(CustomerAddressService::class);

    $service->upsert($customer->id, 'billing', 'تهران', 'تهران', null, null);
    $service->upsert($customer->id, 'billing', 'اصفهان', 'اصفهان', 'خیابان چهارباغ', '8100000000');

    expect(DB::table('customer_addresses')->where('customer_id', $customer->id)->count())->toBe(1);
    $row = DB::table('customer_addresses')->where('customer_id', $customer->id)->sole();
    expect($row->province)->toBe('اصفهان')->and($row->address)->toBe('خیابان چهارباغ');
});

it('does nothing when every field is empty — no row for an order with no address data', function () {
    $customer = Customer::factory()->create();

    app(CustomerAddressService::class)->upsert($customer->id, 'billing', null, null, null, null);

    expect(DB::table('customer_addresses')->where('customer_id', $customer->id)->exists())->toBeFalse();
});

it('trims and cuts province/city/postcode to their column widths, never rejecting an order over it', function () {
    $customer = Customer::factory()->create();

    app(CustomerAddressService::class)->upsert($customer->id, 'billing', '  تهران  ', str_repeat('ش', 90), null, str_repeat('1', 30));

    $row = DB::table('customer_addresses')->where('customer_id', $customer->id)->sole();
    expect($row->province)->toBe('تهران')
        ->and(mb_strlen($row->city))->toBe(80)
        ->and(mb_strlen($row->postcode))->toBe(20);
});
