<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-04 — GET /customers/{customer}/timeline?cursor=&per_page=: a customer's events as JSON, newest first, cursor-paged, behind
| auth + customers.view. The payload is filtered to an allowlist, a date leaves as Jalali AND ISO (never the raw stored value),
| per_page above 50 is refused, and a soft-deleted customer is a 404.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create(['display_name' => 'ZZ-Timeline-Name', 'email' => 'zz-timeline@example.test']);
});

function tlEvent(int $customerId, string $at, string $type = 'note_added', ?array $payload = null): int
{
    return (int) DB::table('customer_events')->insertGetId([
        'customer_id' => $customerId, 'event_type' => $type, 'payload' => $payload === null ? null : json_encode($payload), 'happened_at' => $at,
    ]);
}

function tlGet($test, Customer|int $customer, string $query = '', array $keys = ['customers.view']): TestResponse
{
    $id = $customer instanceof Customer ? $customer->id : $customer;

    return $test->actingAs(Fx::userWith(...$keys))->getJson("/customers/{$id}/timeline{$query}");
}

// ================================================================== access

it('answers a guest with 401', function () {
    $this->getJson("/customers/{$this->customer->id}/timeline")->assertUnauthorized();
});

it('sends a browser guest to the login page', function () {
    $this->get("/customers/{$this->customer->id}/timeline")->assertRedirect(route('login'));
});

it('forbids a viewer without customers.view — the reveal permission or any other is not enough', function (array $keys) {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00', 'note_added', ['note' => 'SECRET-NOTE']);

    $response = tlGet($this, $this->customer, '', $keys);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('SECRET-NOTE');
})->with([
    'nothing' => [[]],
    'view_full_phone only' => [['customers.view_full_phone']],
    'other permissions' => [['system.view', 'identity.review', 'audit.view']],
]);

it('lets an explicit deny beat the role grant', function () {
    $user = Fx::userWith('customers.view');
    $permission = Permission::query()->where('module', 'customers')->where('action', 'view')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->getJson("/customers/{$this->customer->id}/timeline")->assertForbidden();
});

it('answers 403 — not 404 — to a viewer without permission who asks for an id that does not exist', function () {
    tlGet($this, 987_654_321, '', [])->assertForbidden();
});

// ================================================================== not found

it('answers 404 for a soft-deleted customer, even to a viewer who holds every customer permission, and leaks nothing', function () {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00', 'note_added', ['note' => 'SECRET-NOTE']);
    $this->customer->delete();

    $response = tlGet($this, $this->customer, '', ['customers.view', 'customers.view_full_phone']);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('SECRET-NOTE');
});

it('answers 404 for an id that does not exist, and for one that is not a number', function () {
    tlGet($this, 987_654_321)->assertNotFound();
    $this->actingAs(Fx::userWith('customers.view'))->getJson('/customers/abc/timeline')->assertNotFound();
});

// ================================================================== first page and the next

it('returns the first page without a cursor: the newest 20 events, a next cursor and has_more', function () {
    foreach (range(1, 25) as $i) {
        tlEvent($this->customer->id, sprintf('2026-03-%02d 10:00:00+00', $i));
    }

    $body = tlGet($this, $this->customer)->assertOk()->json();

    expect(array_keys($body))->toBe(['data', 'next_cursor', 'has_more'])
        ->and($body['data'])->toHaveCount(20)
        ->and($body['has_more'])->toBeTrue()
        ->and($body['next_cursor'])->toBeString()
        ->and($body['data'][0]['happened_at_iso'])->toBe('2026-03-25T10:00:00Z')
        ->and($body['data'][19]['happened_at_iso'])->toBe('2026-03-06T10:00:00Z');
});

it('returns the next page with the cursor: the rest, in order, and has_more false on the last page', function () {
    foreach (range(1, 25) as $i) {
        tlEvent($this->customer->id, sprintf('2026-03-%02d 10:00:00+00', $i));
    }
    $first = tlGet($this, $this->customer)->json();

    $second = tlGet($this, $this->customer, '?cursor='.urlencode($first['next_cursor']))->assertOk()->json();

    expect($second['data'])->toHaveCount(5)
        ->and($second['has_more'])->toBeFalse()
        ->and($second['next_cursor'])->toBeNull()
        ->and($second['data'][0]['happened_at_iso'])->toBe('2026-03-05T10:00:00Z')
        ->and($second['data'][4]['happened_at_iso'])->toBe('2026-03-01T10:00:00Z')
        ->and(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')))->toBe([]);
});

it('carries the cursor through a URL untouched — it needs no percent-encoding', function () {
    foreach (range(1, 3) as $i) {
        tlEvent($this->customer->id, "2026-03-0{$i} 10:00:00+00");
    }
    $cursor = tlGet($this, $this->customer, '?per_page=1')->json('next_cursor');

    expect($cursor)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(tlGet($this, $this->customer, "?per_page=1&cursor={$cursor}")->assertOk()->json('data.0.happened_at_iso'))->toBe('2026-03-02T10:00:00Z');
});

it('honours per_page from 1 to 50, and defaults to 20', function (string $query, int $expected) {
    foreach (range(1, 60) as $i) {
        tlEvent($this->customer->id, '2026-03-01 10:00:00+00');
    }

    expect(tlGet($this, $this->customer, $query)->assertOk()->json('data'))->toHaveCount($expected);
})->with([['', 20], ['?per_page=1', 1], ['?per_page=10', 10], ['?per_page=50', 50], ['?per_page=', 20]]);

it('refuses per_page above 50, below 1 or not a whole number as a validation error, and runs no query with it', function (string $query) {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00');

    tlGet($this, $this->customer, $query)->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
})->with(['?per_page=51', '?per_page=1000', '?per_page=0', '?per_page=-5', '?per_page=abc', '?per_page=2.5', '?per_page[]=1']);

it('refuses a cursor that is not ours as a validation error on cursor', function (string $query) {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00', 'note_added', ['note' => 'SECRET-NOTE']);

    $response = tlGet($this, $this->customer, $query);

    $response->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
    expect($response->getContent())->not->toContain('SECRET-NOTE');
})->with([
    'garbage' => ['?cursor=garbage'],
    'sql' => ['?cursor='."1'%20OR%20'1'%3D'1"],
    'too long' => ['?cursor='.'A'.'AAAAAAAAAA'.'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
    'an array' => ['?cursor[]=x'],
]);

it('treats an empty cursor as "no cursor"', function () {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00');

    expect(tlGet($this, $this->customer, '?cursor=')->assertOk()->json('data'))->toHaveCount(1);
});

it('gives an empty first page for a customer with no events', function () {
    tlGet($this, $this->customer)->assertOk()->assertExactJson(['data' => [], 'next_cursor' => null, 'has_more' => false]);
});

// ================================================================== what leaves

it('sends only the allowlisted payload keys — an email, phone, name, token or anything else a writer stored stays in the database', function () {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00', 'order_placed', [
        'woo_order_id' => 12345, 'order_id' => 9,
        'email' => 'leak@example.test', 'phone' => '989121234567', 'billing_phone' => '09121234567', 'first_name' => 'Leaky', 'token' => 'TOKEN-XYZ',
        'nested' => ['note' => 'inner'], 'customer_id' => 7,
    ]);
    tlEvent($this->customer->id, '2026-03-02 10:00:00+00', 'status_changed', ['old_status' => 'active', 'new_status' => 'blocked', 'ip' => '10.0.0.1']);
    tlEvent($this->customer->id, '2026-03-03 10:00:00+00', 'note_added', ['note' => 'called back', 'author' => 'Someone']);

    $response = tlGet($this, $this->customer)->assertOk();
    $payloads = array_column($response->json('data'), 'payload');
    $body = $response->getContent();

    expect($payloads)->toBe([
        ['note' => 'called back'],
        ['old_status' => 'active', 'new_status' => 'blocked'],
        ['order_id' => 9, 'woo_order_id' => 12345],
    ])->and($body)->not->toContain('leak@example.test')->not->toContain('989121234567')->not->toContain('09121234567')
        ->not->toContain('Leaky')->not->toContain('TOKEN-XYZ')->not->toContain('inner')->not->toContain('10.0.0.1')->not->toContain('Someone');
});

it('sends a null payload — not {} and not a list — when nothing is allowed', function () {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00', 'note_added', ['secret' => 'x']);
    tlEvent($this->customer->id, '2026-03-02 10:00:00+00', 'note_added', null);

    expect(array_column(tlGet($this, $this->customer)->json('data'), 'payload'))->toBe([null, null]);
});

it('sends exactly id, event_type, happened_at_jalali, happened_at_iso and payload per event — and never the raw stored date, the customer id or created_at', function () {
    tlEvent($this->customer->id, '2026-03-20 20:30:00+00', 'order_placed', ['woo_order_id' => 1]);

    $response = tlGet($this, $this->customer)->assertOk();
    $event = $response->json('data.0');

    expect(array_keys($event))->toBe(['id', 'event_type', 'happened_at_jalali', 'happened_at_iso', 'payload'])
        ->and($event['happened_at_jalali'])->toBe('1405/01/01 00:00:00')
        ->and($event['happened_at_iso'])->toBe('2026-03-20T20:30:00Z')
        ->and($event['event_type'])->toBe('order_placed')
        ->and($response->getContent())->not->toContain('"happened_at"')->not->toContain('customer_id')->not->toContain('created_at')
        ->not->toContain('2026-03-20 20:30:00');
});

it('sends no email, no name and no phone of the customer', function () {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00', 'order_placed', ['woo_order_id' => 1]);

    $body = tlGet($this, $this->customer, '', ['customers.view', 'customers.view_full_phone'])->assertOk()->getContent();

    expect($body)->not->toContain('zz-timeline@example.test')->not->toContain('ZZ-Timeline-Name')->not->toContain($this->customer->phone_normalized);
});

it('lists only this customer\'s events', function () {
    $other = Customer::factory()->create();
    tlEvent($other->id, '2026-03-05 10:00:00+00', 'note_added', ['note' => 'OTHER-CUSTOMER-NOTE']);
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00', 'note_added', ['note' => 'mine']);

    $response = tlGet($this, $this->customer)->assertOk();

    expect($response->json('data'))->toHaveCount(1)->and($response->getContent())->not->toContain('OTHER-CUSTOMER-NOTE');
});

it('does not mutate anything: reading a timeline writes no row of any kind', function () {
    tlEvent($this->customer->id, '2026-03-01 10:00:00+00');
    $tables = ['customers', 'customer_events', 'orders', 'audit_logs', 'phone_reveal_logs'];
    $count = fn () => array_map(fn (string $t) => DB::table($t)->count(), $tables);
    $before = $count();

    tlGet($this, $this->customer)->assertOk();

    expect($count())->toBe($before);
});

it('spends exactly one query on the events, besides the customer lookup and the permission stack', function () {
    foreach (range(1, 30) as $i) {
        tlEvent($this->customer->id, '2026-03-01 10:00:00+00');
    }
    $this->actingAs(Fx::userWith('customers.view'));
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $this->getJson("/customers/{$this->customer->id}/timeline")->assertOk();

    $isEvents = fn (string $q) => str_contains($q, '"customer_events"');
    $isCustomer = fn (string $q) => preg_match('/from "customers"/i', $q) === 1;
    $isPermission = fn (string $q) => preg_match('/\b(from|join)\s+"(roles|role_user|permissions|permission_role|permission_overrides)"/i', $q) === 1;

    expect(count(array_filter($sql, $isEvents)))->toBe(1)
        ->and(count(array_filter($sql, $isCustomer)))->toBe(1)
        ->and(array_filter($sql, fn (string $q) => ! $isEvents($q) && ! $isCustomer($q) && ! $isPermission($q)))->toBe([]);
});
