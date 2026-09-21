<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-06 — GET /orders/{order}: one order's detail, in at most 3 queries (order, items, customer — the third skipped when there is
| none). The phone is always masked, for every viewer — the audited PhoneRevealButton is the only full-number path (P3-02).
| Orders have no soft delete, so a missing id is simply 404. needs_phone_review and the customer's profile_url are asserted.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
});

function osGet($test, Order|int $order, array $keys = ['orders.view'])
{
    $id = $order instanceof Order ? $order->id : $order;

    return $test->actingAs(Fx::userWith(...$keys))->get("/orders/{$id}");
}

// ================================================================== access

it('redirects a guest to login', function () {
    $order = Order::factory()->create();

    $this->get("/orders/{$order->id}")->assertRedirect(route('login'));
});

it('forbids a signed-in user without orders.view', function () {
    $order = Order::factory()->create();

    $this->actingAs(Fx::userWith())->get("/orders/{$order->id}")->assertForbidden();
    $this->actingAs(Fx::userWith('customers.view'))->get("/orders/{$order->id}")->assertForbidden();
});

it('lets an explicit deny beat the role grant', function () {
    $order = Order::factory()->create();
    $user = Fx::userWith('orders.view');
    $permission = Permission::query()->where('module', 'orders')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->get("/orders/{$order->id}")->assertForbidden();
});

it('answers 403 — not 404 — to a viewer without permission who asks for an id that does not exist', function () {
    osGet($this, 987_654_321, [])->assertForbidden();
});

// ================================================================== not found

it('answers 404 for an order that does not exist — orders have no soft delete', function () {
    osGet($this, 987_654_321)->assertNotFound();
});

it('answers 404 for a non-numeric id', function () {
    $this->actingAs(Fx::userWith('orders.view'))->get('/orders/abc')->assertNotFound();
});

// ================================================================== the page

it('renders the orders/show component with the order, its items and its customer', function () {
    $customer = Customer::factory()->create(['display_name' => 'ZZ-Show-Customer']);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'woo_order_id' => 60101]);
    OrderItem::query()->create(['order_id' => $order->id, 'sku' => 'SKU-1', 'name_snapshot' => 'Shirt', 'qty' => 2, 'unit_price' => 100_000, 'line_subtotal' => 200_000, 'line_total' => 200_000]);

    osGet($this, $order)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('orders/show')
        ->where('order.woo_order_id', 60101)
        ->has('order.items', 1)
        ->where('order.customer.display_name', 'ZZ-Show-Customer'));
});

it('sends every documented order field, in Jalali and ISO, and int Toman money', function () {
    $order = Order::factory()->create([
        'woo_order_id' => 77001, 'status' => 'completed', 'total' => 500_000, 'subtotal' => 480_000,
        'discount_total' => 0, 'shipping_total' => 20_000, 'tax_total' => 0, 'refunded_total' => 0,
        'is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => '2026-03-20 20:30:00+00',
        'customer_id' => null, 'needs_phone_review' => true,
    ]);

    $data = osGet($this, $order)->inertiaProps()['order'];

    expect($data)->toBe([
        'id' => $order->id,
        'woo_order_id' => 77001,
        'status' => 'completed',
        'total' => 500_000,
        'subtotal' => 480_000,
        'discount_total' => 0,
        'shipping_total' => 20_000,
        'tax_total' => 0,
        'refunded_total' => 0,
        'is_realized' => true,
        'is_fully_refunded' => false,
        'needs_phone_review' => true,
        'ordered_at_jalali' => '1405/01/01 00:00:00',
        'ordered_at_iso' => '2026-03-20T20:30:00Z',
        'items' => [],
        'customer' => null,
    ]);
});

// ================================================================== the required test: query budget

it('spends at most 3 queries besides the permission stack: order, items, customer', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);
    OrderItem::query()->create(['order_id' => $order->id, 'name_snapshot' => 'Shirt', 'qty' => 1, 'unit_price' => 10_000, 'line_subtotal' => 10_000, 'line_total' => 10_000]);

    $this->actingAs(Fx::userWith('orders.view'));
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $this->get("/orders/{$order->id}")->assertOk();

    $isData = fn (string $q) => preg_match('/\b(from|join)\s+"(orders|order_items|customers)"/i', $q) === 1;
    $isPermission = fn (string $q) => preg_match('/\b(from|join)\s+"(roles|role_user|permissions|permission_role|permission_overrides)"/i', $q) === 1;
    $data = array_values(array_filter($sql, $isData));

    expect($data)->toHaveCount(3)
        ->and(array_filter($sql, fn (string $q) => ! $isData($q) && ! $isPermission($q)))->toBe([]);
});

it('spends only 2 queries when there is no customer to look up — the third is skipped, not error-handled', function () {
    $order = Order::factory()->create(['customer_id' => null, 'needs_phone_review' => true]);
    $this->actingAs(Fx::userWith('orders.view'));
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $this->get("/orders/{$order->id}")->assertOk();

    $isData = fn (string $q) => preg_match('/\b(from|join)\s+"(orders|order_items|customers)"/i', $q) === 1;

    expect(array_values(array_filter($sql, $isData)))->toHaveCount(2);
});

// ================================================================== phone masking

it('masks the customer phone for every viewer, view_full_phone holder included', function (array $keys) {
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567']);
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $response = osGet($this, $order, $keys);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('order.customer.phone_masked', '********4567'));
    expect($response->getContent())->not->toContain('989121234567');
})->with([
    'view only' => [['orders.view']],
    'view and reveal' => [['orders.view', 'customers.view_full_phone']],
]);

// ================================================================== needs_phone_review flag

it('sets needs_phone_review true for a phone-less order, false otherwise', function () {
    $flagged = Order::factory()->create(['customer_id' => null, 'needs_phone_review' => true]);
    $clean = Order::factory()->create(['needs_phone_review' => false]);

    expect(osGet($this, $flagged)->inertiaProps()['order']['needs_phone_review'])->toBeTrue()
        ->and(osGet($this, $clean)->inertiaProps()['order']['needs_phone_review'])->toBeFalse();
});

// ================================================================== customer profile_url

it('sends the customer\'s Customer 360 URL', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $url = osGet($this, $order)->inertiaProps()['order']['customer']['profile_url'];

    expect($url)->toEndWith("/customers/{$customer->id}");
});

it('sends a null customer for an order that has none, never a missing key or an error', function () {
    $order = Order::factory()->create(['customer_id' => null, 'needs_phone_review' => true]);

    expect(osGet($this, $order)->inertiaProps()['order']['customer'])->toBeNull();
});

// ================================================================== items

it('lists items with name, sku, qty, unit_price and line_total, in insertion order', function () {
    $order = Order::factory()->create();
    OrderItem::query()->create(['order_id' => $order->id, 'sku' => 'A', 'name_snapshot' => 'First', 'qty' => 1, 'unit_price' => 10_000, 'line_subtotal' => 10_000, 'line_total' => 10_000]);
    OrderItem::query()->create(['order_id' => $order->id, 'sku' => null, 'name_snapshot' => 'Second', 'qty' => 3, 'unit_price' => 20_000, 'line_subtotal' => 60_000, 'line_total' => 60_000]);

    $items = osGet($this, $order)->inertiaProps()['order']['items'];

    expect($items)->toBe([
        ['name' => 'First', 'sku' => 'A', 'qty' => 1, 'unit_price' => 10_000, 'line_total' => 10_000],
        ['name' => 'Second', 'sku' => null, 'qty' => 3, 'unit_price' => 20_000, 'line_total' => 60_000],
    ]);
});

it('lists no items besides this order\'s own', function () {
    $order = Order::factory()->create();
    $other = Order::factory()->create();
    OrderItem::query()->create(['order_id' => $other->id, 'name_snapshot' => 'Not mine', 'qty' => 1, 'unit_price' => 1, 'line_subtotal' => 1, 'line_total' => 1]);

    expect(osGet($this, $order)->inertiaProps()['order']['items'])->toBe([]);
});

it('sends no email of the customer', function () {
    $customer = Customer::factory()->create(['email' => 'zz-order-detail@example.test']);
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    expect(osGet($this, $order)->getContent())->not->toContain('zz-order-detail@example.test');
});

it('does not mutate anything: a page view writes no row', function () {
    $order = Order::factory()->create();
    $before = [DB::table('orders')->count(), DB::table('order_items')->count(), DB::table('audit_logs')->count()];

    osGet($this, $order);

    expect([DB::table('orders')->count(), DB::table('order_items')->count(), DB::table('audit_logs')->count()])->toBe($before);
});
