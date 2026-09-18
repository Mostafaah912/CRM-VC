<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Customers\Enums\AddressType;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\IdentityConflictStatus;
use App\Modules\Customers\Enums\IdentitySource;
use App\Modules\Customers\Enums\LifecycleStage;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerIdentity;
use App\Support\Exceptions\InvalidPhoneException;
use Illuminate\Database\QueryException;

it('creates a customer from the factory with sane defaults', function () {
    $customer = Customer::factory()->create()->refresh();

    expect($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->lifecycle_stage)->toBe(LifecycleStage::Prospect)
        ->and($customer->metrics_dirty)->toBeTrue()
        ->and($customer->phone_normalized)->toMatch('/^989\d{9}$/');
});

it('normalizes any phone format on write, so one phone can never become two customers', function (string $raw) {
    $customer = Customer::query()->create(['phone_normalized' => $raw, 'phone_raw_last' => $raw]);

    expect($customer->phone_normalized)->toBe('989123456789')
        ->and($customer->phone_raw_last)->toBe($raw);
})->with(['09123456789', '+989123456789', '۰۹۱۲۳۴۵۶۷۸۹', "\u{200F}0912-345-6789"]);

it('rejects an invalid phone instead of storing garbage', function () {
    Customer::query()->create(['phone_normalized' => '02112345678']);
})->throws(InvalidPhoneException::class);

it('treats differently-formatted copies of the same phone as a duplicate', function () {
    Customer::query()->create(['phone_normalized' => '09123456789']);

    expect(fn () => Customer::query()->create(['phone_normalized' => '+98 912 345 6789']))
        ->toThrow(QueryException::class);
});

it('soft deletes a customer and keeps the row', function () {
    $customer = Customer::factory()->create();

    $customer->delete();

    expect(Customer::query()->count())->toBe(0)
        ->and(Customer::withTrashed()->count())->toBe(1);
});

it('links identities, conflicts, addresses and notes to a customer', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $customer->identities()->create(['source' => IdentitySource::WooUser, 'source_id' => '55']);
    $customer->identityConflicts()->create(['existing_name' => 'Ali', 'incoming_name' => 'Reza', 'woo_order_id' => 900, 'reason' => 'name_mismatch']);
    $customer->addresses()->create(['type' => AddressType::Shipping, 'city' => 'Tehran', 'is_default' => true]);
    $customer->notes()->create(['user_id' => $user->id, 'body' => 'prefers evening calls']);

    $customer->refresh();

    expect($customer->identities)->toHaveCount(1)
        ->and($customer->identities->first()->source)->toBe(IdentitySource::WooUser)
        ->and($customer->identityConflicts->first()->status)->toBe(IdentityConflictStatus::Pending)
        ->and($customer->addresses->first()->type)->toBe(AddressType::Shipping)
        ->and($customer->addresses->first()->is_default)->toBeTrue()
        ->and($customer->notes->first()->author->is($user))->toBeTrue()
        ->and(CustomerIdentity::query()->first()->customer->is($customer))->toBeTrue();
});

it('resolves an identity conflict to a reviewing user', function () {
    $customer = Customer::factory()->create();
    $reviewer = User::factory()->create();
    $conflict = $customer->identityConflicts()->create(['reason' => 'name_mismatch']);

    $conflict->update([
        'status' => IdentityConflictStatus::ConfirmedSame,
        'resolved_by' => $reviewer->id,
        'resolved_at' => now(),
    ]);

    expect($conflict->refresh()->resolver->is($reviewer))->toBeTrue()
        ->and($conflict->status)->toBe(IdentityConflictStatus::ConfirmedSame);
});
