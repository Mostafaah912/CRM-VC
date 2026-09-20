<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\IdentityConflict;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\OrderSyncService;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooPayloads;

/*
| GATE 1 blocker: a Woo order with no usable billing phone (real order 15091: a guest, billing.phone empty) used to abort the
| whole sync run. It is now STORED — customer_id NULL, needs_phone_review TRUE — with ONE pending identity_conflicts row
| (reason `no_phone`, no names, no phone) and it is counted by reconciliation like any other order. "No usable phone" is decided
| by PhoneNormalizer alone. Never a placeholder customer, never a rejected order, never a phone or name in a log or an error.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    Http::preventStrayRequests();
});

/**
 * @param  list<array<array-key, mixed>>  $orders
 */
function phonelessFake(array $orders): FakeWooClient
{
    return new FakeWooClient([new WooFixture('orders', 1, [], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => (string) count($orders)], $orders)]);
}

function phonelessSync(array $orders): void
{
    app()->instance(WooClient::class, phonelessFake($orders));

    app(OrderSyncService::class)->syncPage(1);
}

/** A recorded order under a new id, as a guest, with the billing phone replaced (null = the key is absent). */
function phonelessOrder(int $wooId, ?string $phone, bool $guest = true): array
{
    $order = WooPayloads::set(WooPayloads::first('orders'), 'id', $wooId);
    $order = WooPayloads::set($order, 'number', (string) $wooId);
    $order = WooPayloads::set($order, 'customer_id', $guest ? 0 : 11);

    return $phone === null ? WooPayloads::without($order, 'billing.phone') : WooPayloads::set($order, 'billing.phone', $phone);
}

// ================================================================== the order is stored, flagged, with no customer

it('stores an order with no usable billing phone: no customer, flagged for review, its items and money intact', function (?string $phone) {
    phonelessSync([phonelessOrder(15091, $phone)]);

    $order = Order::sole();
    expect($order->woo_order_id)->toBe(15091)
        ->and($order->customer_id)->toBeNull()
        ->and($order->needs_phone_review)->toBeTrue()
        ->and($order->total)->toBe(403880)
        ->and($order->is_realized)->toBeTrue()
        ->and(OrderItem::where('order_id', $order->id)->count())->toBeGreaterThan(0)
        ->and(Customer::withTrashed()->count())->toBe(0);
})->with([
    'no billing.phone key' => [null],
    'empty string' => [''],
    'blanks only' => ['   '],
    'too short' => ['12345'],
    'not a mobile number' => ['08123456789'],
]);

it('stores a phone-less order of a registered Woo user the same way, and creates no identity for it', function () {
    phonelessSync([phonelessOrder(15092, '', guest: false)]);

    expect(Order::sole()->only(['customer_id', 'needs_phone_review']))->toBe(['customer_id' => null, 'needs_phone_review' => true])
        ->and(DB::table('customer_identities')->count())->toBe(0);
});

it('records exactly one pending no_phone conflict for it, with the order id and no customer, name or phone', function () {
    phonelessSync([phonelessOrder(15091, '')]);

    $conflict = IdentityConflict::sole();
    expect($conflict->reason)->toBe('no_phone')
        ->and($conflict->status->value)->toBe('pending')
        ->and($conflict->woo_order_id)->toBe(15091)
        ->and($conflict->customer_id)->toBeNull()
        ->and($conflict->existing_name)->toBeNull()
        ->and($conflict->incoming_name)->toBeNull()
        ->and($conflict->resolved_by)->toBeNull();
});

it('is idempotent: syncing the same phone-less order again changes nothing and raises nothing new', function () {
    phonelessSync([phonelessOrder(15091, '')]);
    phonelessSync([phonelessOrder(15091, '')]);
    phonelessSync([phonelessOrder(15091, '')]);

    expect(Order::count())->toBe(1)
        ->and(IdentityConflict::count())->toBe(1)
        ->and(Order::sole()->needs_phone_review)->toBeTrue();
});

it('records one conflict per phone-less order', function () {
    phonelessSync([phonelessOrder(15091, ''), phonelessOrder(15093, null), phonelessOrder(15094, '12345')]);

    expect(IdentityConflict::orderBy('woo_order_id')->pluck('woo_order_id')->all())->toBe([15091, 15093, 15094])
        ->and(IdentityConflict::where('reason', 'no_phone')->count())->toBe(3);
});

// ================================================================== a page with phone-less orders does not break the sync

it('syncs a whole page that mixes phone-less orders with good ones, and reports every one as synced', function () {
    $good = WooPayloads::first('orders');
    app()->instance(WooClient::class, phonelessFake([phonelessOrder(15091, ''), $good, phonelessOrder(15093, null), phonelessOrder(15094, 'abc')]));

    $result = app(OrderSyncService::class)->syncPage(1);

    expect($result->orders)->toBe(4)
        ->and(Order::count())->toBe(4)
        ->and(Order::where('needs_phone_review', true)->count())->toBe(3)
        ->and(Order::whereNull('customer_id')->count())->toBe(3)
        ->and(Order::where('woo_order_id', $good['id'])->sole()->needs_phone_review)->toBeFalse()
        ->and(Customer::count())->toBe(1)
        ->and(IdentityConflict::count())->toBe(3);
});

// ================================================================== regression: a good phone still attaches a customer

it('still attaches a customer and leaves the order unflagged when the phone is usable', function () {
    phonelessSync([WooPayloads::first('orders')]);

    $order = Order::sole();
    expect($order->customer_id)->toBe(Customer::sole()->id)
        ->and($order->needs_phone_review)->toBeFalse()
        ->and(IdentityConflict::count())->toBe(0);
});

it('attaches the customer and clears the flag when Woo later gives the same order a usable phone', function () {
    phonelessSync([phonelessOrder(15091, '')]);
    expect(Order::sole()->needs_phone_review)->toBeTrue();

    phonelessSync([phonelessOrder(15091, '09000000123')]);

    $order = Order::sole();
    expect($order->customer_id)->toBe(Customer::sole()->id)
        ->and($order->needs_phone_review)->toBeFalse()
        ->and(Order::count())->toBe(1);
});

it('keeps the customer of an order that already has one when Woo later drops its phone — history is never detached', function () {
    phonelessSync([phonelessOrder(15091, '09000000123')]);
    $customerId = Order::sole()->customer_id;

    phonelessSync([phonelessOrder(15091, '')]);

    $order = Order::sole();
    expect($order->customer_id)->toBe($customerId)
        ->and($order->needs_phone_review)->toBeFalse()
        ->and(IdentityConflict::count())->toBe(0);
});

// ================================================================== reconciliation counts it

it('counts a phone-less order in the window totals reconciliation compares, like any other order', function () {
    phonelessSync([phonelessOrder(15091, '')]);
    $orderedAt = Order::sole()->ordered_at;

    $totals = app(OrderService::class)->totalsInWindow(
        CarbonImmutable::instance($orderedAt)->subHour(),
        CarbonImmutable::instance($orderedAt)->addHour(),
    );

    expect($totals->count)->toBe(1)->and($totals->realizedRevenue)->toBe(403880);
});

// ================================================================== nothing sensitive leaves the sync

it('never writes the phone or the customer name to a log, an error or the conflict row', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
    });
    $order = phonelessOrder(15091, '0812-345-6789');
    $order = WooPayloads::set($order, 'billing.first_name', 'ZZFIRSTNAME');
    $order = WooPayloads::set($order, 'billing.last_name', 'ZZLASTNAME');

    phonelessSync([$order]);

    $everything = json_encode([
        $logged,
        IdentityConflict::query()->get()->toArray(),
        DB::table('customers')->get(),
    ], JSON_UNESCAPED_UNICODE);

    expect($everything)->not->toContain('0812')->not->toContain('345')->not->toContain('6789')
        ->and($everything)->not->toContain('ZZFIRSTNAME')->not->toContain('ZZLASTNAME');
});

it('no longer throws for a phone-less order: the run-stopping exception is gone', function () {
    expect(file_exists(app_path('Modules/Orders/Exceptions/OrderCustomerUnresolvedException.php')))->toBeFalse();
});

// ================================================================== the database enforces the pairing

it('refuses, in the database, an order with no customer that is not flagged for review', function () {
    Order::factory()->create();

    expect(fn () => DB::table('orders')->insert([
        'woo_order_id' => 777001, 'customer_id' => null, 'needs_phone_review' => false,
        'status' => 'processing', 'ordered_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('accepts an order with no customer when it is flagged, and defaults the flag to false for the rest', function () {
    DB::table('orders')->insert(['woo_order_id' => 777002, 'customer_id' => null, 'needs_phone_review' => true, 'status' => 'processing', 'ordered_at' => now()]);
    $normal = Order::factory()->create();

    expect(DB::table('orders')->where('woo_order_id', 777002)->value('needs_phone_review'))->toBeTrue()
        ->and($normal->fresh()->needs_phone_review)->toBeFalse();
});

it('allows only one no_phone conflict per order at the database level, but one for each different order', function () {
    DB::table('identity_conflicts')->insert(['customer_id' => null, 'woo_order_id' => 800, 'reason' => 'no_phone', 'status' => 'pending']);
    DB::table('identity_conflicts')->insert(['customer_id' => null, 'woo_order_id' => 801, 'reason' => 'no_phone', 'status' => 'pending']);
    expect(DB::table('identity_conflicts')->count())->toBe(2);

    // Last: a failed statement aborts the surrounding test transaction in PostgreSQL.
    expect(fn () => DB::table('identity_conflicts')->insert(['customer_id' => null, 'woo_order_id' => 800, 'reason' => 'no_phone', 'status' => 'pending']))
        ->toThrow(QueryException::class);
});

it('gives the factory a phoneless state that satisfies the pairing', function () {
    $order = Order::factory()->phoneless()->create();

    expect($order->customer_id)->toBeNull()->and($order->needs_phone_review)->toBeTrue();
});
