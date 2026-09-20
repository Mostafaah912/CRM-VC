<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\PageCursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-05 — GET /customers/{customer}/orders?cursor=&per_page=: the Orders tab. ALL of a customer's orders, newest first, cursor-paged
| over (ordered_at DESC, id DESC), 25 a page (max 50), behind auth + customers.view; total_count is its own COUNT(*). Money leaves as
| int Toman exactly as stored (the store's unit is Toman — nothing is divided). Soft-deleted customer: 404.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create(['display_name' => 'ZZ-Orders-Name', 'email' => 'zz-orders@example.test']);
});

function ordInsert(int $customerId, string $at, array $overrides = []): int
{
    static $wooId = 7_000_000;

    return (int) DB::table('orders')->insertGetId([
        'woo_order_id' => ++$wooId, 'customer_id' => $customerId, 'status' => 'completed', 'is_realized' => true,
        'total' => 1_000_000, 'ordered_at' => $at, ...$overrides,
    ]);
}

function ordGet($test, Customer|int $customer, string $query = '', array $keys = ['customers.view']): TestResponse
{
    $id = $customer instanceof Customer ? $customer->id : $customer;

    return $test->actingAs(Fx::userWith(...$keys))->getJson("/customers/{$id}/orders{$query}");
}

// ================================================================== access

it('answers a guest with 401, and sends a browser guest to the login page', function () {
    $this->getJson("/customers/{$this->customer->id}/orders")->assertUnauthorized();
    $this->get("/customers/{$this->customer->id}/orders")->assertRedirect(route('login'));
});

it('forbids a viewer without customers.view — the reveal or note permission, or any other, is not enough', function (array $keys) {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 424242]);

    $response = ordGet($this, $this->customer, '', $keys);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('424242');
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

    $this->actingAs($user)->getJson("/customers/{$this->customer->id}/orders")->assertForbidden();
});

it('answers 403 — not 404 — to a viewer without permission who asks for an id that does not exist', function () {
    ordGet($this, 987_654_321, '', [])->assertForbidden();
});

it('answers 404 for a soft-deleted customer even to a viewer who holds every customer permission, and for an id that is missing or not a number', function () {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 424242]);
    $this->customer->delete();

    $response = ordGet($this, $this->customer, '', ['customers.view', 'customers.view_full_phone', 'customers.note', 'customers.manage_notes']);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('424242');
    ordGet($this, 987_654_321)->assertNotFound();
    $this->actingAs(Fx::userWith('customers.view'))->getJson('/customers/abc/orders')->assertNotFound();
});

// ================================================================== the page

it('returns the newest 25 orders first, with a cursor, has_more and the exact total', function () {
    foreach (range(1, 60) as $i) {
        ordInsert($this->customer->id, CarbonImmutable::parse('2026-01-01', 'UTC')->addDays($i)->toDateTimeString().'+00');
    }

    $body = ordGet($this, $this->customer)->assertOk()->json();

    expect(array_keys($body))->toBe(['data', 'next_cursor', 'has_more', 'total_count'])
        ->and($body['data'])->toHaveCount(25)
        ->and($body['has_more'])->toBeTrue()
        ->and($body['next_cursor'])->toBeString()
        ->and($body['total_count'])->toBe(60)
        ->and($body['data'][0]['ordered_at_iso'])->toBe('2026-03-02T00:00:00Z')
        ->and($body['data'][24]['ordered_at_iso'])->toBe('2026-02-06T00:00:00Z');
});

it('walks 60 orders in pages of 25, 25 and 10 — every order once, in order, with the same total each time', function () {
    $ids = [];

    foreach (range(1, 60) as $i) {
        $ids[] = ordInsert($this->customer->id, CarbonImmutable::parse('2026-01-01', 'UTC')->addDays($i)->toDateTimeString().'+00', ['woo_order_id' => 100 + $i]);
    }
    $expected = array_map(fn (int $i) => 100 + $i, range(60, 1));

    $pages = [];
    $cursor = null;

    do {
        $page = ordGet($this, $this->customer, $cursor === null ? '' : '?cursor='.$cursor)->assertOk()->json();
        $pages[] = $page;
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($pages) < 10);

    expect(array_map(fn (array $p) => count($p['data']), $pages))->toBe([25, 25, 10])
        ->and(array_column($pages, 'has_more'))->toBe([true, true, false])
        ->and(array_column($pages, 'total_count'))->toBe([60, 60, 60])
        ->and(array_merge(...array_map(fn (array $p) => array_column($p['data'], 'woo_order_id'), $pages)))->toBe($expected)
        ->and($pages[2]['next_cursor'])->toBeNull();
});

it('has no next page when a page is exactly full and nothing follows', function () {
    foreach (range(1, 25) as $i) {
        ordInsert($this->customer->id, '2026-03-01 10:00:00+00');
    }

    $body = ordGet($this, $this->customer)->json();

    expect($body['data'])->toHaveCount(25)->and($body['has_more'])->toBeFalse()->and($body['next_cursor'])->toBeNull();
});

it('pages across a tie: orders of one instant are shown once each, newest id first', function () {
    foreach (range(1, 5) as $i) {
        ordInsert($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 500 + $i]);
    }

    $first = ordGet($this, $this->customer, '?per_page=2')->json();
    $second = ordGet($this, $this->customer, '?per_page=2&cursor='.$first['next_cursor'])->json();
    $third = ordGet($this, $this->customer, '?per_page=2&cursor='.$second['next_cursor'])->json();

    expect(array_merge(array_column($first['data'], 'woo_order_id'), array_column($second['data'], 'woo_order_id'), array_column($third['data'], 'woo_order_id')))->toBe([505, 504, 503, 502, 501])
        ->and($third['has_more'])->toBeFalse();
});

it('does not repeat or skip an order when a newer one arrives between two pages', function () {
    foreach (range(1, 6) as $day) {
        ordInsert($this->customer->id, "2026-03-0{$day} 10:00:00+00", ['woo_order_id' => 600 + $day]);
    }
    $first = ordGet($this, $this->customer, '?per_page=3')->json();

    ordInsert($this->customer->id, '2026-03-20 10:00:00+00', ['woo_order_id' => 699]);
    $second = ordGet($this, $this->customer, '?per_page=3&cursor='.$first['next_cursor'])->json();

    expect(array_merge(array_column($first['data'], 'woo_order_id'), array_column($second['data'], 'woo_order_id')))->toBe([606, 605, 604, 603, 602, 601])
        ->and($second['total_count'])->toBe(7);
});

it('gives an empty page for a customer with no orders, and for a cursor past the oldest', function () {
    ordGet($this, $this->customer)->assertOk()->assertExactJson(['data' => [], 'next_cursor' => null, 'has_more' => false, 'total_count' => 0]);

    $only = ordInsert($this->customer->id, '2026-03-01 10:00:00+00');
    $cursor = PageCursor::encode(['ordered_at' => CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'), 'id' => $only]);

    expect(ordGet($this, $this->customer, "?cursor={$cursor}")->json('data'))->toBe([]);
});

it('honours per_page from 1 to 50 and defaults to 25', function (string $query, int $expected) {
    foreach (range(1, 60) as $i) {
        ordInsert($this->customer->id, '2026-03-01 10:00:00+00');
    }

    expect(ordGet($this, $this->customer, $query)->assertOk()->json('data'))->toHaveCount($expected);
})->with([['', 25], ['?per_page=1', 1], ['?per_page=10', 10], ['?per_page=50', 50], ['?per_page=', 25]]);

it('refuses per_page above 50, below 1 or not a whole number as a validation error', function (string $query) {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00');

    ordGet($this, $this->customer, $query)->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
})->with(['?per_page=51', '?per_page=1000', '?per_page=0', '?per_page=-5', '?per_page=abc', '?per_page=2.5', '?per_page[]=1']);

it('refuses a cursor that is not an orders cursor as a validation error on cursor', function (string $query) {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 424242]);

    $response = ordGet($this, $this->customer, $query);

    $response->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
    expect($response->getContent())->not->toContain('424242');
})->with([
    'garbage' => ['?cursor=garbage'],
    'sql' => ['?cursor='."1'%20OR%20'1'%3D'1"],
    'a products cursor' => ['?cursor='.rtrim(strtr(base64_encode('{"last_ordered_at":"2026-03-01T10:00:00Z","name":"x"}'), '+/', '-_'), '=')],
    'an array' => ['?cursor[]=x'],
]);

it('treats an empty cursor as "no cursor"', function () {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00');

    expect(ordGet($this, $this->customer, '?cursor=')->assertOk()->json('data'))->toHaveCount(1);
});

it('keeps a cursor inside its own customer: another customer\'s position reveals none of that customer\'s orders', function () {
    $other = Customer::factory()->create();
    $otherOrder = ordInsert($other->id, '2026-03-05 10:00:00+00', ['woo_order_id' => 909]);
    ordInsert($this->customer->id, '2026-03-02 10:00:00+00', ['woo_order_id' => 801]);
    ordInsert($this->customer->id, '2026-03-04 10:00:00+00', ['woo_order_id' => 802]);
    $cursor = PageCursor::encode(['ordered_at' => CarbonImmutable::parse('2026-03-05 10:00:00', 'UTC'), 'id' => $otherOrder]);

    expect(array_column(ordGet($this, $this->customer, "?cursor={$cursor}")->json('data'), 'woo_order_id'))->toBe([802, 801]);
});

// ================================================================== what leaves

it('sends exactly woo_order_id, status, total, is_realized and the two dates per order — and no internal id, customer id or raw date', function () {
    ordInsert($this->customer->id, '2026-03-20 20:30:00+00', ['woo_order_id' => 12345, 'status' => 'processing', 'total' => 344_890, 'is_realized' => true]);

    $response = ordGet($this, $this->customer)->assertOk();
    $order = $response->json('data.0');

    expect($order)->toBe([
        'woo_order_id' => 12345,
        'status' => 'processing',
        'total' => 344_890,
        'is_realized' => true,
        'ordered_at_jalali' => '1405/01/01 00:00:00',
        'ordered_at_iso' => '2026-03-20T20:30:00Z',
    ])->and($response->getContent())->not->toContain('customer_id')->not->toContain('"id"')->not->toContain('"ordered_at"')->not->toContain('2026-03-20 20:30:00');
});

it('sends money as int Toman exactly as stored — nothing divided, nothing rounded', function () {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00', ['total' => 403_885]); // not a multiple of 10: a /10 would lose the 5

    expect(ordGet($this, $this->customer)->json('data.0.total'))->toBe(403_885);
});

it('marks a realized and a not-realized order, whatever their status text', function () {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00', ['status' => 'cancelled', 'is_realized' => false]);
    ordInsert($this->customer->id, '2026-03-02 10:00:00+00', ['status' => 'completed', 'is_realized' => true]);

    expect(array_column(ordGet($this, $this->customer)->json('data'), 'is_realized'))->toBe([true, false]);
});

it('lists orders of every status and leaves out other customers\' and soft-deleted orders, from the list and the count', function () {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00', ['woo_order_id' => 1, 'status' => 'pending', 'is_realized' => false]);
    ordInsert($this->customer->id, '2026-03-02 10:00:00+00', ['woo_order_id' => 2, 'deleted_at' => '2026-03-03 00:00:00+00']);
    ordInsert(Customer::factory()->create()->id, '2026-03-04 10:00:00+00', ['woo_order_id' => 3]);

    $body = ordGet($this, $this->customer)->json();

    expect(array_column($body['data'], 'woo_order_id'))->toBe([1])->and($body['total_count'])->toBe(1);
});

it('sends no email, no name and no phone of the customer', function () {
    ordInsert($this->customer->id, '2026-03-01 10:00:00+00');

    $body = ordGet($this, $this->customer, '', ['customers.view', 'customers.view_full_phone'])->assertOk()->getContent();

    expect($body)->not->toContain('zz-orders@example.test')->not->toContain('ZZ-Orders-Name')->not->toContain($this->customer->phone_normalized);
});

it('writes nothing, and spends one query each on the customer, the page and the count besides the permission stack', function () {
    foreach (range(1, 30) as $i) {
        ordInsert($this->customer->id, '2026-03-01 10:00:00+00');
    }
    $this->actingAs(Fx::userWith('customers.view'));
    $tables = ['customers', 'orders', 'customer_events', 'audit_logs'];
    $count = fn () => array_map(fn (string $t) => DB::table($t)->count(), $tables);
    $before = $count();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $this->getJson("/customers/{$this->customer->id}/orders")->assertOk();
    $queries = $sql; // the request's own queries, before the counting below adds any
    $after = $count();

    $isCustomer = fn (string $q) => preg_match('/from "customers"/i', $q) === 1;
    $isOrders = fn (string $q) => preg_match('/from "orders"/i', $q) === 1;
    $isPermission = fn (string $q) => preg_match('/\b(from|join)\s+"(roles|role_user|permissions|permission_role|permission_overrides)"/i', $q) === 1;

    expect($after)->toBe($before)
        ->and(count(array_filter($queries, $isCustomer)))->toBe(1)
        ->and(count(array_filter($queries, $isOrders)))->toBe(2) // the page and the count
        ->and(array_filter($queries, fn (string $q) => ! $isCustomer($q) && ! $isOrders($q) && ! $isPermission($q)))->toBe([]);
});
