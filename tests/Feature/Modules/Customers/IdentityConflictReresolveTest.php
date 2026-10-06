<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Customers\Services\IdentityConflictReresolveService;
use Illuminate\Support\Facades\DB;

/*
| P6-13 (TEST FIRST). A diagnosis of the real pending identity_conflicts on dev found no safe, unambiguous
| PersonNameNormalizer gap to fix: the only repeating character-level patterns found (hamza-bearing letters,
| e.g. آ vs ا) directly contradict an existing, deliberate test (PersonNameNormalizerTest: "alef vs alef-madda
| are different letters") — reversing that needs the user's own decision, not a silent code change, so nothing
| in PersonNameNormalizer changed. This command is the re-runnable, idempotent machinery PRD/the task asked
| for regardless: it re-applies the CURRENT (unchanged) rule to every pending last_name_mismatch row, entirely
| from locally-stored data, so it closes nothing today and becomes useful the moment a real fix ships.
|
| Reconstruction note: incoming_name is `first + ' ' + last` composed at write time (CustomerIdentityService);
| the raw incoming last name alone was never stored. PersonNameNormalizer::normalize() strips the separating
| space, so normalize(incoming_name) is exactly normalize(incoming_first) . normalize(incoming_last)
| concatenated — meaning normalize(incoming_last) is an exact suffix of it. A match requires that suffix to
| equal the customer's current normalized last name, at least 2 characters, so a coincidental one-character
| overlap can never close a conflict it should not.
*/

function seedPendingMismatch(string $existingLast, string $incomingName): IdentityConflict
{
    $customer = Customer::factory()->create(['last_name' => $existingLast]);

    return IdentityConflict::create([
        'customer_id' => $customer->id,
        'existing_name' => 'ایکس '.$existingLast,
        'incoming_name' => $incomingName,
        'woo_order_id' => 9001,
        'reason' => 'last_name_mismatch',
        'status' => 'pending',
    ]);
}

it('closes a conflict whose incoming name now normalizes to the same last name, as confirmed_same', function () {
    // customer.last_name "رضایی" (plain yeh); the stored incoming_name's last token is identical already —
    // simulates "the fix would now consider these the same" without inventing a fuzzy rule of its own.
    $conflict = seedPendingMismatch('رضایی', 'علی رضایی');

    $result = app(IdentityConflictReresolveService::class)->reresolvePendingByCurrentRules();

    expect($result->examined)->toBe(1)
        ->and($result->closedAsSame)->toBe(1)
        ->and($result->stillPending)->toBe(0)
        ->and($conflict->fresh()->status->value)->toBe('confirmed_same')
        ->and($conflict->fresh()->resolved_at)->not->toBeNull();
});

// ------------------------------------------------------- P6-14 phase 4: needs_review clearing

it('clears needs_review once the customer\'s last pending conflict closes', function () {
    $conflict = seedPendingMismatch('رضایی', 'علی رضایی');
    $conflict->customer->update(['needs_review' => true]);

    app(IdentityConflictReresolveService::class)->reresolvePendingByCurrentRules();

    expect($conflict->customer->fresh()->needs_review)->toBeFalse();
});

it('keeps needs_review true when another pending conflict remains for the same customer', function () {
    $customer = Customer::factory()->create(['last_name' => 'رضایی', 'needs_review' => true]);
    $closable = IdentityConflict::create([
        'customer_id' => $customer->id, 'existing_name' => 'ایکس رضایی', 'incoming_name' => 'علی رضایی',
        'woo_order_id' => 9001, 'reason' => 'last_name_mismatch', 'status' => 'pending',
    ]);
    IdentityConflict::create([
        'customer_id' => $customer->id, 'existing_name' => 'ایکس رضایی', 'incoming_name' => 'علی کریمی',
        'woo_order_id' => 9002, 'reason' => 'last_name_mismatch', 'status' => 'pending',
    ]);

    app(IdentityConflictReresolveService::class)->reresolvePendingByCurrentRules();

    expect($closable->fresh()->status->value)->toBe('confirmed_same')
        ->and($customer->fresh()->needs_review)->toBeTrue();
});

it('leaves a genuinely different last name pending — never loosens the match', function () {
    $conflict = seedPendingMismatch('رضایی', 'علی کریمی');

    $result = app(IdentityConflictReresolveService::class)->reresolvePendingByCurrentRules();

    expect($result->examined)->toBe(1)
        ->and($result->closedAsSame)->toBe(0)
        ->and($result->stillPending)->toBe(1)
        ->and($conflict->fresh()->status->value)->toBe('pending');
});

it('never closes on a one-character coincidental suffix overlap', function () {
    $conflict = seedPendingMismatch('ی', 'علی');

    $result = app(IdentityConflictReresolveService::class)->reresolvePendingByCurrentRules();

    expect($result->closedAsSame)->toBe(0)
        ->and($conflict->fresh()->status->value)->toBe('pending');
});

it('is idempotent: a second run finds nothing left to close', function () {
    seedPendingMismatch('رضایی', 'علی رضایی');
    $service = app(IdentityConflictReresolveService::class);

    $first = $service->reresolvePendingByCurrentRules();
    $second = $service->reresolvePendingByCurrentRules();

    expect($first->closedAsSame)->toBe(1)
        ->and($second->examined)->toBe(0)
        ->and($second->closedAsSame)->toBe(0);
});

it('ignores a no_phone conflict (no customer, nothing to compare) and an already-resolved row', function () {
    DB::table('identity_conflicts')->insert(['customer_id' => null, 'woo_order_id' => 15091, 'reason' => 'no_phone', 'status' => 'pending', 'created_at' => now()]);
    $customer = Customer::factory()->create(['last_name' => 'رضایی']);
    IdentityConflict::create([
        'customer_id' => $customer->id, 'existing_name' => 'ایکس رضایی', 'incoming_name' => 'علی رضایی',
        'woo_order_id' => 9002, 'reason' => 'last_name_mismatch', 'status' => 'ignored',
    ]);

    $result = app(IdentityConflictReresolveService::class)->reresolvePendingByCurrentRules();

    expect($result->examined)->toBe(0);
});

it('does not touch a conflict whose customer no longer has a last name', function () {
    $conflict = seedPendingMismatch('رضایی', 'علی رضایی');
    $conflict->customer->update(['last_name' => null]);

    $result = app(IdentityConflictReresolveService::class)->reresolvePendingByCurrentRules();

    expect($result->closedAsSame)->toBe(0)
        ->and($conflict->fresh()->status->value)->toBe('pending');
});
