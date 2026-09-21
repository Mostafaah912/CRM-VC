<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-06 — GET /orders: the order list, LEFT JOIN customers for the display name (an order with no customer, needs_phone_review, is
| never dropped by the join). Filters combine with AND: woo_order_id exact, status (validated against what is actually stored),
| is_realized, needs_phone_review (this list's own filter, distinct from P3-01's customers.needs_review), and a Jalali day range
| on ordered_at. Offset pagination, 25 a page, behind auth + orders.view.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
});

function olGet($test, string $query = '', array $keys = ['orders.view'])
{
    return $test->actingAs(Fx::userWith(...$keys))->get('/orders'.$query);
}

function olOrder(array $overrides = []): Order
{
    return Order::factory()->create($overrides);
}

// ================================================================== access

it('redirects a guest to login', function () {
    $this->get('/orders')->assertRedirect(route('login'));
});

it('forbids a signed-in user without orders.view', function () {
    olOrder();

    $this->actingAs(Fx::userWith())->get('/orders')->assertForbidden();
    $this->actingAs(Fx::userWith('customers.view', 'system.view'))->get('/orders')->assertForbidden();
});

it('lets an explicit deny beat the role grant', function () {
    $user = Fx::userWith('orders.view');
    $permission = Permission::query()->where('module', 'orders')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->get('/orders')->assertForbidden();
});

// ================================================================== the page

it('renders the orders/index component with the documented props', function () {
    olOrder();

    olGet($this)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('orders/index')
        ->has('orders.data')
        ->has('filters')
        ->has('options.statuses'));
});

it('sends the order and the joined customer name, and no other customer field', function () {
    $customer = Customer::factory()->create(['display_name' => 'ZZ-Order-Customer', 'email' => 'zz-order@example.test']);
    olOrder(['customer_id' => $customer->id, 'woo_order_id' => 55501, 'status' => 'completed', 'total' => 344_890, 'is_realized' => true, 'ordered_at' => '2026-03-20 20:30:00+00']);

    $row = olGet($this)->inertiaProps()['orders']['data'][0];

    expect($row)->toBe([
        'id' => $row['id'],
        'woo_order_id' => 55501,
        'status' => 'completed',
        'total' => 344_890,
        'is_realized' => true,
        'needs_phone_review' => false,
        'ordered_at_jalali' => '1405/01/01 00:00:00',
        'ordered_at_iso' => '2026-03-20T20:30:00Z',
        'customer_id' => $customer->id,
        'customer_display_name' => 'ZZ-Order-Customer',
    ])->and(json_encode($row))->not->toContain('zz-order@example.test')->not->toContain('phone_normalized')->not->toContain('email');
});

// ================================================================== the one required test: order without customer

it('shows an order without a customer as display_name null — not an error', function () {
    olOrder(['customer_id' => null, 'needs_phone_review' => true, 'woo_order_id' => 15091]);

    $row = olGet($this)->inertiaProps()['orders']['data'][0];

    expect($row['customer_id'])->toBeNull()
        ->and($row['customer_display_name'])->toBeNull()
        ->and($row['needs_phone_review'])->toBeTrue();
});

// ================================================================== the one required test: needs_phone_review filter

it('filters by needs_phone_review — the flag this list owns, distinct from P3-01\'s customers.needs_review', function () {
    olOrder(['customer_id' => null, 'needs_phone_review' => true, 'woo_order_id' => 1]);
    olOrder(['needs_phone_review' => false, 'woo_order_id' => 2]);

    $flagged = olGet($this, '?needs_phone_review=1')->inertiaProps()['orders']['data'];
    $clean = olGet($this, '?needs_phone_review=0')->inertiaProps()['orders']['data'];

    expect(array_column($flagged, 'woo_order_id'))->toBe([1])
        ->and(array_column($clean, 'woo_order_id'))->toBe([2]);
});

it('shows every order when needs_phone_review is not asked', function () {
    olOrder(['customer_id' => null, 'needs_phone_review' => true, 'woo_order_id' => 1]);
    olOrder(['needs_phone_review' => false, 'woo_order_id' => 2]);

    expect(olGet($this)->inertiaProps()['orders']['data'])->toHaveCount(2);
});

// ================================================================== other filters

it('filters by an exact woo_order_id', function () {
    olOrder(['woo_order_id' => 1001]);
    olOrder(['woo_order_id' => 1002]);

    expect(array_column(olGet($this, '?woo_order_id=1001')->inertiaProps()['orders']['data'], 'woo_order_id'))->toBe([1001]);
});

it('rejects a status not actually stored, instead of silently ignoring it', function () {
    olOrder(['status' => 'completed']);

    olGet($this, '?status=nonexistent-status')->assertRedirect()->assertSessionHasErrors(['status']);
});

it('filters by status', function () {
    olOrder(['status' => 'completed', 'woo_order_id' => 1]);
    olOrder(['status' => 'pending', 'woo_order_id' => 2]);

    expect(array_column(olGet($this, '?status=completed')->inertiaProps()['orders']['data'], 'woo_order_id'))->toBe([1]);
});

it('filters by is_realized', function () {
    olOrder(['is_realized' => true, 'woo_order_id' => 1]);
    olOrder(['is_realized' => false, 'woo_order_id' => 2]);

    expect(array_column(olGet($this, '?is_realized=1')->inertiaProps()['orders']['data'], 'woo_order_id'))->toBe([1])
        ->and(array_column(olGet($this, '?is_realized=0')->inertiaProps()['orders']['data'], 'woo_order_id'))->toBe([2]);
});

// ================================================================== the one required test: Jalali date range

it('filters by the Jalali ordered_at range correctly — [from, to] inclusive both ends, on Tehran calendar days', function () {
    // 1405/01/01 starts 2026-03-20 20:30 UTC; 1405/01/02 starts 2026-03-21 20:30 UTC.
    olOrder(['woo_order_id' => 1, 'ordered_at' => '2026-03-20 10:00:00+00']); // 1404/12/29 — before the range
    olOrder(['woo_order_id' => 2, 'ordered_at' => '2026-03-20 21:00:00+00']); // 1405/01/01, just inside
    olOrder(['woo_order_id' => 3, 'ordered_at' => '2026-03-21 21:00:00+00']); // 1405/01/02, just inside (the "to" day)
    olOrder(['woo_order_id' => 4, 'ordered_at' => '2026-03-22 10:00:00+00']); // 1405/01/02 still, later that day

    $rows = olGet($this, '?ordered_from=1405/01/01&ordered_to=1405/01/02')->inertiaProps()['orders']['data'];

    expect(array_column($rows, 'woo_order_id'))->toEqualCanonicalizing([2, 3, 4]);
});

it('rejects a Jalali date that is not a real day, and one written the wrong way round', function () {
    olOrder();

    olGet($this, '?ordered_from=1405/13/01')->assertRedirect()->assertSessionHasErrors(['ordered_from']);
    olGet($this, '?ordered_from=1405/06/10&ordered_to=1405/06/01')->assertRedirect()->assertSessionHasErrors(['ordered_to']);
});

it('combines every filter with AND', function () {
    olOrder(['woo_order_id' => 1, 'status' => 'completed', 'is_realized' => true, 'needs_phone_review' => false]);
    olOrder(['woo_order_id' => 2, 'status' => 'completed', 'is_realized' => false, 'needs_phone_review' => false]);

    $rows = olGet($this, '?status=completed&is_realized=1')->inertiaProps()['orders']['data'];

    expect(array_column($rows, 'woo_order_id'))->toBe([1]);
});

// ================================================================== order and paging

it('lists orders newest first', function () {
    olOrder(['woo_order_id' => 1, 'ordered_at' => '2026-09-01 00:00:00+00']);
    olOrder(['woo_order_id' => 3, 'ordered_at' => '2026-09-03 00:00:00+00']);
    olOrder(['woo_order_id' => 2, 'ordered_at' => '2026-09-02 00:00:00+00']);

    expect(array_column(olGet($this)->inertiaProps()['orders']['data'], 'woo_order_id'))->toBe([3, 2, 1]);
});

it('pages 25 at a time', function () {
    $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');

    foreach (range(0, 29) as $i) {
        olOrder(['woo_order_id' => $i, 'ordered_at' => $start->addMinutes($i)]);
    }

    $first = olGet($this)->inertiaProps()['orders'];
    $second = olGet($this, '?page=2')->inertiaProps()['orders'];

    expect($first['data'])->toHaveCount(25)
        ->and($first['total'])->toBe(30)
        ->and($first['data'][0]['woo_order_id'])->toBe(29)
        ->and($second['data'])->toHaveCount(5)
        ->and($second['current_page'])->toBe(2);
});

it('does not mutate anything: a page view writes no row', function () {
    olOrder();
    $before = [DB::table('orders')->count(), DB::table('audit_logs')->count()];

    olGet($this);

    expect([DB::table('orders')->count(), DB::table('audit_logs')->count()])->toBe($before);
});
