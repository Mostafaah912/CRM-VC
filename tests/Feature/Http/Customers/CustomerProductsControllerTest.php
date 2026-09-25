<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\PageCursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-05 — GET /customers/{customer}/products?cursor=&per_page=: the Products tab. One row per NAME the customer bought, from
| realized orders only, with total quantity, number of orders and the last purchase; cursor-paged over (last_ordered_at DESC,
| name ASC), 25 a page (max 50). Grouped by name_snapshot, not product_id (an unresolved product has no product_id).
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create(['display_name' => 'ZZ-Products-Name', 'email' => 'zz-products@example.test']);
});

function prdOrder(int $customerId, string $at, array $overrides = []): int
{
    static $wooId = 8_000_000;

    return (int) DB::table('orders')->insertGetId([
        'woo_order_id' => ++$wooId, 'customer_id' => $customerId, 'status' => 'completed', 'is_realized' => true,
        'total' => 1_000_000, 'ordered_at' => $at, ...$overrides,
    ]);
}

function prdItem(int $orderId, string $name, ?string $sku = 'SKU-1', int $qty = 1, ?int $productId = null): void
{
    DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => $productId, 'sku' => $sku, 'name_snapshot' => $name, 'qty' => $qty]);
}

/** One order holding one item — the common case. */
function prdBuy(int $customerId, string $at, string $name, ?string $sku = 'SKU-1', int $qty = 1): int
{
    $order = prdOrder($customerId, $at);
    prdItem($order, $name, $sku, $qty);

    return $order;
}

function prdGet($test, Customer|int $customer, string $query = '', array $keys = ['customers.view']): TestResponse
{
    $id = $customer instanceof Customer ? $customer->id : $customer;

    return $test->actingAs(Fx::userWith(...$keys))->getJson("/customers/{$id}/products{$query}");
}

/** @return list<string> */
function prdNames(array $page): array
{
    return array_column($page['data'], 'name');
}

// ================================================================== access

it('answers a guest with 401, and sends a browser guest to the login page', function () {
    $this->getJson("/customers/{$this->customer->id}/products")->assertUnauthorized();
    $this->get("/customers/{$this->customer->id}/products")->assertRedirect(route('login'));
});

it('forbids a viewer without customers.view', function (array $keys) {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'SECRET-PRODUCT');

    $response = prdGet($this, $this->customer, '', $keys);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('SECRET-PRODUCT');
})->with([
    'nothing' => [[]],
    'view_full_phone only' => [['customers.view_full_phone']],
    'note and manage_notes' => [['customers.note', 'customers.manage_notes']],
    'other permissions' => [['system.view', 'identity.review', 'orders.view']],
]);

it('lets an explicit deny beat the role grant', function () {
    $user = Fx::userWith('customers.view');
    $permission = Permission::query()->where('module', 'customers')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->getJson("/customers/{$this->customer->id}/products")->assertForbidden();
});

it('answers 403 — not 404 — to a viewer without permission who asks for an id that does not exist', function () {
    prdGet($this, 987_654_321, '', [])->assertForbidden();
});

it('answers 404 for a soft-deleted customer, and for an id that is missing or not a number', function () {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'SECRET-PRODUCT');
    $this->customer->delete();

    $response = prdGet($this, $this->customer, '', ['customers.view', 'customers.view_full_phone', 'customers.note', 'customers.manage_notes']);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('SECRET-PRODUCT');
    prdGet($this, 987_654_321)->assertNotFound();
    $this->actingAs(Fx::userWith('customers.view'))->getJson('/customers/abc/products')->assertNotFound();
});

// ================================================================== grouping

it('groups by name: total quantity, the number of orders that held it, and the last purchase', function () {
    // 2026-03-20 20:30 UTC = 1405/01/01 00:00 Tehran
    $a = prdOrder($this->customer->id, '2026-03-01 10:00:00+00');
    prdItem($a, 'Shirt', 'SH-1', 2);
    prdItem($a, 'Shirt', 'SH-1', 3); // two lines of one order: qty adds up, the order counts once
    prdBuy($this->customer->id, '2026-03-20 20:30:00+00', 'Shirt', 'SH-1', 4);

    expect(prdGet($this, $this->customer)->assertOk()->json('data'))->toBe([[
        'name' => 'Shirt', 'sku' => 'SH-1', 'total_qty' => 9, 'order_count' => 2,
        'last_ordered_at_jalali' => '1405/01/01 00:00:00', 'last_ordered_at_iso' => '2026-03-20T20:30:00Z',
    ]]);
});

it('groups by the name it was sold under, not by product_id: two names of one product are two rows, one name over two products is one', function () {
    $shirt = Product::factory()->create();
    $coat = Product::factory()->create();
    prdItem(prdOrder($this->customer->id, '2026-03-01 10:00:00+00'), 'Shirt old name', 'S', 1, $shirt->id);
    prdItem(prdOrder($this->customer->id, '2026-03-02 10:00:00+00'), 'Shirt new name', 'S', 1, $shirt->id);
    prdItem(prdOrder($this->customer->id, '2026-03-03 10:00:00+00'), 'Same name', 'A', 1, $shirt->id);
    prdItem(prdOrder($this->customer->id, '2026-03-04 10:00:00+00'), 'Same name', 'B', 1, $coat->id);

    $page = prdGet($this, $this->customer)->json();

    expect(prdNames($page))->toBe(['Same name', 'Shirt new name', 'Shirt old name'])
        ->and($page['data'][0]['order_count'])->toBe(2);
});

it('keeps an item whose product could not be resolved (no product_id), grouped by its name', function () {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'Ghost item', null);
    prdBuy($this->customer->id, '2026-03-02 10:00:00+00', 'Ghost item', null);

    $row = prdGet($this, $this->customer)->json('data.0');

    expect($row['name'])->toBe('Ghost item')->and($row['sku'])->toBeNull()->and($row['order_count'])->toBe(2);
});

it('takes the SKU and the last date from the most recent purchase', function () {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'Shirt', 'OLD-SKU');
    prdBuy($this->customer->id, '2026-03-09 10:00:00+00', 'Shirt', 'NEW-SKU');

    $row = prdGet($this, $this->customer)->json('data.0');

    expect($row['sku'])->toBe('NEW-SKU')->and($row['last_ordered_at_iso'])->toBe('2026-03-09T10:00:00Z');
});

it('counts only realized orders, never a soft-deleted one, and never another customer\'s', function () {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'Real item');
    $cancelled = prdOrder($this->customer->id, '2026-03-02 10:00:00+00', ['status' => 'cancelled', 'is_realized' => false]);
    prdItem($cancelled, 'Cancelled item');
    $deleted = prdOrder($this->customer->id, '2026-03-03 10:00:00+00', ['deleted_at' => '2026-03-04 00:00:00+00']);
    prdItem($deleted, 'Deleted order item');
    prdBuy(Customer::factory()->create()->id, '2026-03-05 10:00:00+00', 'Somebody else item');

    expect(prdNames(prdGet($this, $this->customer)->json()))->toBe(['Real item']);
});

it('takes "realized" from the stored flag, not from a status string in the code', function () {
    config(['woo.realized_statuses' => ['nothing-real']]);
    $order = prdOrder($this->customer->id, '2026-03-01 10:00:00+00', ['status' => 'completed', 'is_realized' => true]);
    prdItem($order, 'Shirt');

    expect(prdNames(prdGet($this, $this->customer)->json()))->toBe(['Shirt']);
});

// ================================================================== order and paging

it('orders by last purchase, newest first, then by name ascending', function () {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'Old');
    prdBuy($this->customer->id, '2026-03-09 10:00:00+00', 'b-tie');
    prdBuy($this->customer->id, '2026-03-09 10:00:00+00', 'a-tie');
    prdBuy($this->customer->id, '2026-03-05 10:00:00+00', 'Middle');

    expect(prdNames(prdGet($this, $this->customer)->json()))->toBe(['a-tie', 'b-tie', 'Middle', 'Old']);
});

it('orders names by byte value, so "10" comes before "9" — the same rule the cursor filter uses, never a numeric comparison', function () {
    foreach (['9', '10', '100', '1e3', '2'] as $name) {
        prdBuy($this->customer->id, '2026-03-09 10:00:00+00', $name);
    }

    $first = prdGet($this, $this->customer, '?per_page=2')->json();
    $second = prdGet($this, $this->customer, '?per_page=2&cursor='.$first['next_cursor'])->json();
    $third = prdGet($this, $this->customer, '?per_page=2&cursor='.$second['next_cursor'])->json();

    expect(array_merge(prdNames($first), prdNames($second), prdNames($third)))->toBe(['10', '100', '1e3', '2', '9']);
});

it('walks 60 products in pages of 25, 25 and 10 — every product once, in order', function () {
    foreach (range(1, 60) as $i) {
        prdBuy($this->customer->id, CarbonImmutable::parse('2026-01-01', 'UTC')->addDays($i)->toDateTimeString().'+00', sprintf('Product %02d', $i));
    }
    $expected = array_map(fn (int $i) => sprintf('Product %02d', $i), range(60, 1));

    $pages = [];
    $cursor = null;

    do {
        $page = prdGet($this, $this->customer, $cursor === null ? '' : '?cursor='.$cursor)->assertOk()->json();
        $pages[] = $page;
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($pages) < 10);

    expect(array_map(fn (array $p) => count($p['data']), $pages))->toBe([25, 25, 10])
        ->and(array_column($pages, 'has_more'))->toBe([true, true, false])
        ->and(array_merge(...array_map('prdNames', $pages)))->toBe($expected)
        ->and(array_keys($pages[0]))->toBe(['data', 'next_cursor', 'has_more']);
});

it('pages across a tie: many products bought at one instant, two at a time, each shown once in name order', function () {
    $names = ['e', 'b', 'd', 'a', 'c'];

    foreach ($names as $name) {
        prdBuy($this->customer->id, '2026-03-09 10:00:00+00', $name);
    }

    $pages = [];
    $cursor = null;

    do {
        $page = prdGet($this, $this->customer, '?per_page=2'.($cursor === null ? '' : '&cursor='.$cursor))->json();
        $pages[] = $page;
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($pages) < 10);

    expect(array_merge(...array_map('prdNames', $pages)))->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and(array_column($pages, 'has_more'))->toBe([true, true, false]);
});

it('carries Persian names through the cursor intact, and does not repeat or skip one', function () {
    $names = ['پیراهن مردانه آستین بلند', 'شلوار کتان ؟ ~ >', "کت\u{200C}و شلوار", 'مانتو "ویژه" / نوروز', 'روسری'];

    foreach ($names as $name) {
        prdBuy($this->customer->id, '2026-03-09 10:00:00+00', $name);
    }
    sort($names, SORT_STRING); // byte order, like the service

    $first = prdGet($this, $this->customer, '?per_page=2')->json();
    $second = prdGet($this, $this->customer, '?per_page=2&cursor='.$first['next_cursor'])->json();
    $third = prdGet($this, $this->customer, '?per_page=2&cursor='.$second['next_cursor'])->json();

    expect(array_merge(prdNames($first), prdNames($second), prdNames($third)))->toBe($names)
        ->and($first['next_cursor'])->toMatch('/^[A-Za-z0-9_-]+$/');
});

it('does not repeat or skip a product when a newer purchase arrives between two pages', function () {
    foreach (['a', 'b', 'c', 'd'] as $i => $name) {
        prdBuy($this->customer->id, sprintf('2026-03-0%d 10:00:00+00', 5 - $i), $name);
    }
    $first = prdGet($this, $this->customer, '?per_page=2')->json();

    prdBuy($this->customer->id, '2026-03-20 10:00:00+00', 'brand new');
    $second = prdGet($this, $this->customer, '?per_page=2&cursor='.$first['next_cursor'])->json();

    expect(array_merge(prdNames($first), prdNames($second)))->toBe(['a', 'b', 'c', 'd']);
});

it('has no next page when a page is exactly full and nothing follows', function () {
    foreach (range(1, 25) as $i) {
        prdBuy($this->customer->id, '2026-03-01 10:00:00+00', "P{$i}");
    }

    $body = prdGet($this, $this->customer)->json();

    expect($body['data'])->toHaveCount(25)->and($body['has_more'])->toBeFalse()->and($body['next_cursor'])->toBeNull();
});

it('gives an empty page for a customer with no purchases', function () {
    prdGet($this, $this->customer)->assertOk()->assertExactJson(['data' => [], 'next_cursor' => null, 'has_more' => false]);
});

it('honours per_page from 1 to 50 and defaults to 25; refuses anything else', function () {
    foreach (range(1, 60) as $i) {
        prdBuy($this->customer->id, '2026-03-01 10:00:00+00', "P{$i}");
    }

    expect(prdGet($this, $this->customer)->json('data'))->toHaveCount(25)
        ->and(prdGet($this, $this->customer, '?per_page=50')->json('data'))->toHaveCount(50)
        ->and(prdGet($this, $this->customer, '?per_page=1')->json('data'))->toHaveCount(1);

    foreach (['?per_page=51', '?per_page=0', '?per_page=abc', '?per_page[]=1'] as $bad) {
        prdGet($this, $this->customer, $bad)->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }
});

it('refuses a cursor that is not a products cursor as a validation error on cursor', function (string $query) {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'SECRET-PRODUCT');

    $response = prdGet($this, $this->customer, $query);

    $response->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
    expect($response->getContent())->not->toContain('SECRET-PRODUCT');
})->with([
    'garbage' => ['?cursor=garbage'],
    'sql' => ['?cursor='."1'%20OR%20'1'%3D'1"],
    'an orders cursor' => ['?cursor='.rtrim(strtr(base64_encode('{"ordered_at":"2026-03-01T10:00:00Z","id":1}'), '+/', '-_'), '=')],
    'a name over 255 characters' => ['?cursor='.rtrim(strtr(base64_encode(json_encode(['last_ordered_at' => '2026-03-01T10:00:00Z', 'name' => str_repeat('x', 256)])), '+/', '-_'), '=')],
]);

it('keeps a cursor inside its own customer', function () {
    prdBuy(Customer::factory()->create()->id, '2026-03-05 10:00:00+00', 'OTHER-CUSTOMER-PRODUCT');
    prdBuy($this->customer->id, '2026-03-02 10:00:00+00', 'mine');
    $cursor = PageCursor::encode(['last_ordered_at' => CarbonImmutable::parse('2026-03-06 00:00:00', 'UTC'), 'name' => '']);

    $response = prdGet($this, $this->customer, "?cursor={$cursor}")->assertOk();

    expect(prdNames($response->json()))->toBe(['mine'])->and($response->getContent())->not->toContain('OTHER-CUSTOMER-PRODUCT');
});

// ================================================================== what leaves

it('sends exactly name, sku, total_qty, order_count and the two dates per product — no id, no raw date, no customer field', function () {
    prdBuy($this->customer->id, '2026-03-20 20:30:00+00', 'Shirt', 'SH-1', 2);

    $response = prdGet($this, $this->customer)->assertOk();

    expect(array_keys($response->json('data.0')))->toBe(['name', 'sku', 'total_qty', 'order_count', 'last_ordered_at_jalali', 'last_ordered_at_iso'])
        ->and($response->getContent())->not->toContain('customer_id')->not->toContain('product_id')->not->toContain('"id"')->not->toContain('"last_ordered_at"')->not->toContain('"ordered_at"');
});

it('sends no email, no name and no phone of the customer', function () {
    prdBuy($this->customer->id, '2026-03-01 10:00:00+00', 'Shirt');

    $body = prdGet($this, $this->customer, '', ['customers.view', 'customers.view_full_phone'])->assertOk()->getContent();

    expect($body)->not->toContain('zz-products@example.test')->not->toContain('ZZ-Products-Name')->not->toContain($this->customer->phone_normalized);
});

it('writes nothing, and reads the customer and the item lines in two queries besides the permission stack', function () {
    foreach (range(1, 30) as $i) {
        prdBuy($this->customer->id, '2026-03-01 10:00:00+00', "P{$i}");
    }
    $this->actingAs(Fx::userWith('customers.view'));
    $tables = ['customers', 'orders', 'order_items', 'customer_events', 'audit_logs'];
    $count = fn () => array_map(fn (string $t) => DB::table($t)->count(), $tables);
    $before = $count();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $this->getJson("/customers/{$this->customer->id}/products")->assertOk();
    $queries = $sql;
    $after = $count();

    $isData = fn (string $q) => preg_match('/\b(from|join)\s+"(customers|orders|order_items)"/i', $q) === 1;
    $isPermission = fn (string $q) => preg_match('/\b(from|join)\s+"(roles|role_user|permissions|permission_role|permission_overrides)"/i', $q) === 1;

    expect($after)->toBe($before)
        ->and(count(array_filter($queries, $isData)))->toBe(2)
        ->and(array_filter($queries, fn (string $q) => ! $isData($q) && ! $isPermission($q)))->toBe([]);
});
