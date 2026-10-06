<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\Artisan;

/*
| `hm:identity-reresolve` (P6-13): thin wrapper composing IdentityConflictReresolveService (Customers) and
| OrderService::countNeedingPhoneReview() (Orders) — the only reason this lives in app/Console/Commands and
| not inside either module (same precedent as hm:nightly-chain, P6-09).
*/

it('reports the needs-phone-review order count and the conflict re-resolution summary', function () {
    Order::factory()->create(['customer_id' => null, 'needs_phone_review' => true]);
    Order::factory()->create(['customer_id' => null, 'needs_phone_review' => true]);

    $customer = Customer::factory()->create(['last_name' => 'رضایی']);
    $conflict = IdentityConflict::create([
        'customer_id' => $customer->id, 'existing_name' => 'ایکس رضایی', 'incoming_name' => 'علی رضایی',
        'woo_order_id' => 9001, 'reason' => 'last_name_mismatch', 'status' => 'pending',
    ]);

    $code = Artisan::call('hm:identity-reresolve');
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('orders with no customer (needs_phone_review): 2')
        ->and($output)->toContain('pending last_name_mismatch examined: 1, closed as confirmed_same: 1, still pending: 0')
        ->and($conflict->fresh()->status->value)->toBe('confirmed_same');
});

it('is safe to run with nothing pending', function () {
    $code = Artisan::call('hm:identity-reresolve');
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('orders with no customer (needs_phone_review): 0')
        ->and($output)->toContain('pending last_name_mismatch examined: 0, closed as confirmed_same: 0, still pending: 0');
});
