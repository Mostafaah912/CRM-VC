<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-03 — GET /customers/{customer}: the Customer 360 page, behind auth + customers.view. A soft-deleted customer is a 404; the
| phone is masked for EVERY viewer (a holder of customers.view_full_phone reveals it through the audited endpoint); no email and
| no first/last name is ever in the response. The Gate 3 promise is a page in at most six queries — asserted here, whole request.
*/

const SHOW_PHONE = '989121234567';

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create([
        'phone_normalized' => SHOW_PHONE,
        'display_name' => 'ZZ-Show-Name',
        'first_name' => 'ZZFirstName',
        'last_name' => 'ZZLastName',
        'email' => 'zz-show@example.test',
    ]);
});

function showAs($test, Customer|int $customer, array $keys = ['customers.view'])
{
    $id = $customer instanceof Customer ? $customer->id : $customer;

    return $test->actingAs(Fx::userWith(...$keys))->get("/customers/{$id}");
}

function showSeed(Customer $customer): void
{
    $product = Product::factory()->create();

    DB::table('customer_metrics')->insert([
        'customer_id' => $customer->id, 'total_orders' => 3, 'total_revenue' => 4_500_000, 'aov' => 1_500_000,
        'last_order_at' => '2026-03-20 20:30:00+00', 'clv_estimated' => 9_000_000, 'clv_confidence' => 'medium',
        'churn_risk_score' => '72.50', 'churn_risk_level' => 'high', 'churn_reason' => 'x', 'computed_at' => now(),
    ]);

    foreach (range(1, 6) as $i) {
        $order = DB::table('orders')->insertGetId([
            'woo_order_id' => 5000 + $i, 'customer_id' => $customer->id, 'status' => 'completed', 'is_realized' => true,
            'total' => 100_000 * $i, 'ordered_at' => "2026-03-0{$i} 10:00:00+00",
        ]);
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product->id, 'sku' => 'S', 'name_snapshot' => 'Shirt', 'qty' => 1]);
    }
}

// ================================================================== access

it('answers a guest asking for JSON with 401, and sends a browser guest to the login page', function () {
    $this->getJson("/customers/{$this->customer->id}")->assertUnauthorized();
    $this->get("/customers/{$this->customer->id}")->assertRedirect(route('login'));
});

it('forbids a viewer without customers.view — the reveal permission or any other is not enough', function (array $keys) {
    $response = showAs($this, $this->customer, $keys);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('ZZ-Show-Name');
})->with([
    'nothing' => [[]],
    'view_full_phone only' => [['customers.view_full_phone']],
    'other permissions' => [['system.view', 'identity.review', 'audit.view']],
]);

it('lets an explicit deny beat the role grant', function () {
    $user = Fx::userWith('customers.view');
    $permission = Permission::query()->where('module', 'customers')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->get("/customers/{$this->customer->id}")->assertForbidden();
});

it('answers 403 — not 404 — to a viewer without permission who asks for an id that does not exist', function () {
    showAs($this, 987_654_321, [])->assertForbidden();
});

// ================================================================== not found

it('answers 404 for a soft-deleted customer, even to a viewer who holds every customer permission', function () {
    $this->customer->delete();

    $response = showAs($this, $this->customer, ['customers.view', 'customers.view_full_phone']);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('ZZ-Show-Name')->not->toContain(SHOW_PHONE);
});

it('answers 404 for an id that does not exist, and for one that is not a number', function () {
    showAs($this, 987_654_321)->assertNotFound();
    $this->actingAs(Fx::userWith('customers.view'))->get('/customers/abc')->assertNotFound();
});

// ================================================================== the page

it('renders the customers/show component with exactly the documented props', function () {
    showSeed($this->customer);

    showAs($this, $this->customer)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/show')
            ->has('profile', fn (Assert $profile) => $profile
                ->hasAll(['customer', 'metrics', 'recent_orders', 'orders_total', 'recent_products', 'timeline'])
                ->where('orders_total', 6)
                ->has('recent_orders', 5)
                ->has('recent_products', 1)
                ->where('timeline', ['data' => [], 'next_cursor' => null, 'has_more' => false])
                ->where('customer.id', $this->customer->id)
                ->where('metrics.churn_risk_level', 'high')
                ->etc(),
            ),
        );
});

it('sends the metrics as null — the page then shows no metrics section — when none were computed', function () {
    showAs($this, $this->customer)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('profile.metrics', null)->where('profile.recent_orders', [])->where('profile.orders_total', 0));
});

// ================================================================== safety

it('masks the phone for a viewer with customers.view, and still masks it for a holder of customers.view_full_phone', function (array $keys) {
    $response = showAs($this, $this->customer, $keys);
    $body = $response->getContent();

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('profile.customer.phone', '********4567'));
    expect($body)->not->toContain(SHOW_PHONE)->not->toContain('09121234567');
})->with([
    'view only' => [['customers.view']],
    'view and reveal' => [['customers.view', 'customers.view_full_phone']],
]);

it('sends no email and no first or last name anywhere in the response', function () {
    showSeed($this->customer);
    $response = showAs($this, $this->customer, ['customers.view', 'customers.view_full_phone']);

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->not->toContain('zz-show@example.test')
        ->not->toContain('ZZFirstName')
        ->not->toContain('ZZLastName')
        ->not->toContain('phone_normalized')
        ->not->toContain('&quot;email&quot;');
});

it('does not reveal anything by being viewed: no audit row, no write of any kind', function () {
    showSeed($this->customer);
    $tables = ['customers', 'customer_metrics', 'orders', 'order_items', 'phone_reveal_logs', 'audit_logs'];
    $count = fn () => array_map(fn (string $t) => DB::table($t)->count(), $tables);
    $before = $count();

    showAs($this, $this->customer, ['customers.view', 'customers.view_full_phone'])->assertOk();

    expect($count())->toBe($before);
});

// ================================================================== the gate

it('loads the profile in six queries on the data tables — and every other query of the request is permission plumbing', function () {
    showSeed($this->customer);
    $this->actingAs(Fx::userWith('customers.view', 'customers.view_full_phone'));
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $this->get("/customers/{$this->customer->id}")->assertOk();

    $isData = fn (string $q) => preg_match('/\b(from|join)\s+"(customers|customer_metrics|orders|order_items|customer_events)"/i', $q) === 1;
    $isPermission = fn (string $q) => preg_match('/\b(from|join)\s+"(roles|role_user|permissions|permission_role|permission_overrides)"/i', $q) === 1;
    $data = array_values(array_filter($sql, $isData));
    $rest = array_values(array_filter($sql, fn (string $q) => ! $isData($q)));

    // 6 is the budget (PRD §18): five for the profile and one for the first page of the timeline. The permission stack (middleware + the shared can() props) is Core's and is not
    // part of the profile's budget; nothing else may creep in beside it.
    expect($data)->toHaveCount(6)
        ->and(array_filter($rest, fn (string $q) => ! $isPermission($q)))->toBe([]);
});

it('sends the first page of the timeline in the profile — filtered and formatted like the timeline endpoint — and none of its raw fields', function () {
    DB::table('customer_events')->insert(['customer_id' => $this->customer->id, 'event_type' => 'order_placed', 'happened_at' => '2026-03-20 20:30:00+00', 'payload' => json_encode(['woo_order_id' => 77, 'phone' => SHOW_PHONE])]);

    $response = showAs($this, $this->customer, ['customers.view', 'customers.view_full_phone']);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('profile.timeline.data', 1)
        ->where('profile.timeline.data.0.happened_at_jalali', '1405/01/01 00:00:00')
        ->where('profile.timeline.data.0.happened_at_iso', '2026-03-20T20:30:00Z')
        ->where('profile.timeline.data.0.payload', ['woo_order_id' => 77])
        ->where('profile.timeline.has_more', false)
        ->where('profile.timeline.next_cursor', null));
    expect($response->getContent())->not->toContain(SHOW_PHONE);
});
