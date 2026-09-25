<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerIdentity;
use App\Modules\Customers\Services\CustomerIdentityService;
use App\Support\Exceptions\InvalidPhoneException;

/*
| P2-06 added one thin entry point to the P2-04 service: resolveForWooOrder() picks the identity source from Woo's
| own data (registered user -> Woo customer id, guest -> the order id), so Orders stays out of Customers' enums.
| Identity rules themselves (phone only, conflicts, idempotency) are P2-04's and are covered there.
*/

it('identifies a registered Woo user by their Woo customer id', function () {
    $customer = app(CustomerIdentityService::class)->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5001);

    $identity = CustomerIdentity::sole();
    expect([$identity->customer_id, $identity->source, $identity->source_id])->toBe([$customer->id, IdentitySource::WooUser, '11']);
});

it('identifies a guest by the order itself', function () {
    $customer = app(CustomerIdentityService::class)->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', null, 5001);

    $identity = CustomerIdentity::sole();
    expect([$identity->customer_id, $identity->source, $identity->source_id])->toBe([$customer->id, IdentitySource::WooGuestOrder, '5001']);
});

it('still resolves by phone alone, so the same phone is one customer whatever the source', function () {
    $service = app(CustomerIdentityService::class);

    $registered = $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5001);
    $guest = $service->resolveForWooOrder('+989000000101', 'مشتری', 'نمونه', null, 5002);

    expect($guest->id)->toBe($registered->id)->and(Customer::count())->toBe(1)->and(CustomerIdentity::count())->toBe(2);
});

it('lets an invalid phone through as PhoneNormalizer\'s own exception', function () {
    expect(fn () => app(CustomerIdentityService::class)->resolveForWooOrder('12345', 'مشتری', 'نمونه', 11, 5001))
        ->toThrow(InvalidPhoneException::class);

    expect(Customer::count())->toBe(0);
});
