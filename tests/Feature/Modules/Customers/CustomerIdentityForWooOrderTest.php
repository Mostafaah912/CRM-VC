<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerIdentity;
use App\Modules\Customers\Services\CustomerIdentityService;
use App\Support\Exceptions\InvalidPhoneException;
use Carbon\CarbonImmutable;

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

// ------------------------------------------------- P6-14 phase 4: province/city/first_seen_at

it('sets province/city and first_seen_at from the order on a brand-new customer', function () {
    $orderedAt = CarbonImmutable::parse('2026-01-15 10:00:00', 'UTC');

    $customer = app(CustomerIdentityService::class)->resolveForWooOrder(
        '09000000101', 'مشتری', 'نمونه', 11, 5001, 'تهران', 'تهران', $orderedAt,
    );

    expect($customer->province)->toBe('تهران')
        ->and($customer->city)->toBe('تهران')
        ->and($customer->first_seen_at?->equalTo($orderedAt))->toBeTrue();
});

it('adopts the most recent non-empty province/city on an existing customer, same rule as names', function () {
    $service = app(CustomerIdentityService::class);
    $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5001, 'تهران', 'تهران', CarbonImmutable::parse('2026-01-01', 'UTC'));

    $customer = $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5002, 'اصفهان', 'اصفهان', CarbonImmutable::parse('2026-02-01', 'UTC'));

    expect($customer->province)->toBe('اصفهان')->and($customer->city)->toBe('اصفهان');
});

it('never blanks a known province/city when a later order has none', function () {
    $service = app(CustomerIdentityService::class);
    $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5001, 'تهران', 'تهران', CarbonImmutable::parse('2026-01-01', 'UTC'));

    $customer = $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5002, null, null, CarbonImmutable::parse('2026-02-01', 'UTC'));

    expect($customer->province)->toBe('تهران')->and($customer->city)->toBe('تهران');
});

it('keeps first_seen_at at the OLDEST order, even when an older order resolves after a newer one', function () {
    $service = app(CustomerIdentityService::class);
    $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5001, null, null, CarbonImmutable::parse('2026-02-01', 'UTC'));

    // Order 5000 is OLDER than 5001 but resolved SECOND (e.g. a resync processing out of strict date order).
    $customer = $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5000, null, null, CarbonImmutable::parse('2026-01-01', 'UTC'));

    expect($customer->first_seen_at?->equalTo(CarbonImmutable::parse('2026-01-01', 'UTC')))->toBeTrue();
});

it('never moves first_seen_at later once it is set', function () {
    $service = app(CustomerIdentityService::class);
    $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5001, null, null, CarbonImmutable::parse('2026-01-01', 'UTC'));

    $customer = $service->resolveForWooOrder('09000000101', 'مشتری', 'نمونه', 11, 5002, null, null, CarbonImmutable::parse('2026-03-01', 'UTC'));

    expect($customer->first_seen_at?->equalTo(CarbonImmutable::parse('2026-01-01', 'UTC')))->toBeTrue();
});
