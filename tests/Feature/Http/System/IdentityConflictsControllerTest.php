<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P2-12 — GET /system/identity-conflicts. Read-only, newest first, 25 per page, behind identity.review. The list is what the
| conflict row itself stores about WHY it exists (its reason and status, the order that raised it, when) plus, since P6-13,
| what a reviewer actually needs to act on it: the customer's id and ALWAYS-masked phone, and the two names being compared.
| Still never: the raw phone, resolved_by, or anything re-read from orders.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
});

function seedConflict(string $createdAt = '2026-09-20 09:00:00+00', array $overrides = []): void
{
    $customer = Customer::query()->first() ?? Customer::factory()->create(['phone_normalized' => '989000000123']);

    DB::table('identity_conflicts')->insert([
        'customer_id' => $customer->id,
        'existing_name' => 'ALPHA-EXISTING-NAME',
        'incoming_name' => 'BETA-INCOMING-NAME',
        'woo_order_id' => 5001,
        'reason' => 'last_name_mismatch',
        'status' => 'pending',
        'created_at' => $createdAt,
        ...$overrides,
    ]);
}

function conflictProps($test, string $query = ''): array
{
    return $test->actingAs(Fx::userWith('identity.review'))->get('/system/identity-conflicts'.$query)->assertOk()->inertiaProps();
}

// ================================================================== access

it('redirects a guest to login', function () {
    $this->get('/system/identity-conflicts')->assertRedirect(route('login'));
});

it('forbids a signed-in user without identity.review — system.view is not enough', function () {
    $this->actingAs(Fx::userWith())->get('/system/identity-conflicts')->assertForbidden();
    $this->actingAs(Fx::userWith('system.view', 'audit.view', 'customers.view'))->get('/system/identity-conflicts')->assertForbidden();
});

it('renders the identity-conflicts component for a user with identity.review', function () {
    seedConflict();

    $this->actingAs(Fx::userWith('identity.review'))
        ->get('/system/identity-conflicts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/identity-conflicts')
            ->has('conflicts.data', 1),
        );
});

// ================================================================== shape and safety

it('sends exactly created_at, status, woo_order_id, reason, customer_id, customer_phone, existing_name and incoming_name — never the raw phone or an internal id beyond the customer\'s own', function () {
    seedConflict(overrides: ['status' => 'ignored']);

    $body = conflictProps($this);
    $page = Fx::pageProps($body);
    $forbidden = array_diff(Fx::FORBIDDEN_KEYS, ['customer_id', 'existing_name', 'incoming_name', 'phone']);

    expect(array_keys($page))->toEqualCanonicalizing(['conflicts'])
        ->and(array_keys($page['conflicts']['data'][0]))->toEqualCanonicalizing(['created_at', 'status', 'woo_order_id', 'reason', 'customer_id', 'customer_phone', 'existing_name', 'incoming_name'])
        ->and(array_intersect(Fx::keysDeep($page), $forbidden))->toBe([])
        ->and(json_encode($page))->toContain('ALPHA-EXISTING-NAME')->toContain('BETA-INCOMING-NAME')->not->toContain('989000000123');
});

it('shows a conflict in Jalali and Tehran time, with its status, order, reason, customer and masked phone as stored', function () {
    // 2026-03-20 20:30 UTC = 1405/01/01 00:00 in Tehran
    $customer = Customer::factory()->create(['phone_normalized' => '989000000123']);
    DB::table('identity_conflicts')->insert([
        'customer_id' => $customer->id, 'existing_name' => 'ALPHA-EXISTING-NAME', 'incoming_name' => 'BETA-INCOMING-NAME',
        'woo_order_id' => 8123, 'reason' => 'last_name_mismatch', 'status' => 'confirmed_same', 'created_at' => '2026-03-20 20:30:00+00',
    ]);

    expect(conflictProps($this)['conflicts']['data'][0])->toBe([
        'created_at' => '1405/01/01 00:00:00',
        'status' => 'confirmed_same',
        'woo_order_id' => 8123,
        'reason' => 'last_name_mismatch',
        'customer_id' => $customer->id,
        'customer_phone' => '********0123',
        'existing_name' => 'ALPHA-EXISTING-NAME',
        'incoming_name' => 'BETA-INCOMING-NAME',
    ]);
});

it('shows a conflict raised without an order as a null order id', function () {
    seedConflict(overrides: ['woo_order_id' => null]);

    expect(conflictProps($this)['conflicts']['data'][0]['woo_order_id'])->toBeNull();
});

it('lists a no_phone conflict, which has no customer, without any name or phone', function () {
    DB::table('identity_conflicts')->insert(['customer_id' => null, 'woo_order_id' => 15091, 'reason' => 'no_phone', 'status' => 'pending', 'created_at' => '2026-09-20 09:00:00+00']);

    $row = conflictProps($this)['conflicts']['data'][0];

    expect($row)->toBe([
        'created_at' => '1405/06/29 12:30:00', 'status' => 'pending', 'woo_order_id' => 15091, 'reason' => 'no_phone',
        'customer_id' => null, 'customer_phone' => null, 'existing_name' => null, 'incoming_name' => null,
    ]);
});

it('passes the stored reason through untouched, reading the customer for its masked phone but never re-reading an order', function () {
    seedConflict(overrides: ['reason' => 'some future reason code']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $row = conflictProps($this)['conflicts']['data'][0];

    $touchedOrders = array_filter($queries, fn (string $sql) => preg_match('/\b(orders|order_items)\b/', $sql) === 1);
    $touchedCustomers = array_filter($queries, fn (string $sql) => preg_match('/\bcustomers\b/', $sql) === 1);

    expect($row['reason'])->toBe('some future reason code')
        ->and(array_values($touchedOrders))->toBe([])
        ->and(array_values($touchedCustomers))->not->toBe([]);
});

// ================================================================== order and paging

it('lists conflicts newest first', function () {
    seedConflict('2026-09-01 00:00:00+00', ['woo_order_id' => 1]);
    seedConflict('2026-09-03 00:00:00+00', ['woo_order_id' => 3]);
    seedConflict('2026-09-02 00:00:00+00', ['woo_order_id' => 2]);

    expect(array_column(conflictProps($this)['conflicts']['data'], 'woo_order_id'))->toBe([3, 2, 1]);
});

it('pages 25 at a time — page 1 is the newest 25 and page 2 the rest', function () {
    $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');

    for ($i = 0; $i < 30; $i++) {
        seedConflict($start->addMinutes($i)->format('Y-m-d H:i:sP'), ['woo_order_id' => $i]);
    }

    $first = conflictProps($this)['conflicts'];
    $second = conflictProps($this, '?page=2')['conflicts'];

    expect($first['data'])->toHaveCount(25)
        ->and($first['total'])->toBe(30)
        ->and($first['last_page'])->toBe(2)
        ->and($first['data'][0]['woo_order_id'])->toBe(29)
        ->and($second['data'])->toHaveCount(5)
        ->and($second['current_page'])->toBe(2)
        ->and(array_column($second['data'], 'woo_order_id'))->toBe([4, 3, 2, 1, 0]);
});

it('shows an empty list when there are no conflicts', function () {
    expect(conflictProps($this)['conflicts']['data'])->toBe([]);
});

it('does not mutate anything: a page view writes no row and resolves nothing', function () {
    seedConflict();
    $before = [DB::table('identity_conflicts')->count(), DB::table('identity_conflicts')->where('status', 'pending')->count(), DB::table('audit_logs')->count()];

    conflictProps($this);

    expect([DB::table('identity_conflicts')->count(), DB::table('identity_conflicts')->where('status', 'pending')->count(), DB::table('audit_logs')->count()])->toBe($before);
});
