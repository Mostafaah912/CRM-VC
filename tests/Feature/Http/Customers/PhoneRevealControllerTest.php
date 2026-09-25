<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-02 — POST /customers/{customer}/reveal-phone: behind auth + customers.view_full_phone, at most 10 a minute per user. It
| returns ONE thing, the customer's full normalized phone, and writes exactly one phone_reveal_logs row per call (who, which
| customer, when, from where) in the same transaction, so a number is never released without its audit row. Nothing about the
| customer beyond the phone is ever in the response, and the phone is never logged.
*/

const REVEAL_PHONE = '989121234567';

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create([
        'phone_normalized' => REVEAL_PHONE,
        'display_name' => 'ZZ-Reveal-Name',
        'email' => 'zz-reveal@example.test',
    ]);
});

/** @param  list<string>  $keys */
function revealAs($test, Customer|int $customer, array $keys = ['customers.view', 'customers.view_full_phone'], array $headers = []): TestResponse
{
    $id = $customer instanceof Customer ? $customer->id : $customer;

    return $test->actingAs(Fx::userWith(...$keys))->withHeaders($headers)->postJson("/customers/{$id}/reveal-phone");
}

// ================================================================== access

it('answers a guest with 401', function () {
    $this->postJson("/customers/{$this->customer->id}/reveal-phone")->assertUnauthorized();
});

it('sends a guest who is not asking for JSON to the login page', function () {
    $this->post("/customers/{$this->customer->id}/reveal-phone")->assertRedirect(route('login'));
});

it('forbids a viewer without customers.view_full_phone — customers.view alone, or any other permission, is not enough — and writes nothing', function (array $keys) {
    $response = revealAs($this, $this->customer, $keys);

    $response->assertForbidden();
    expect(DB::table('phone_reveal_logs')->count())->toBe(0)
        ->and($response->getContent())->not->toContain(REVEAL_PHONE);
})->with([
    'customers.view only' => [['customers.view']],
    'nothing' => [[]],
    'other permissions' => [['customers.view', 'system.view', 'identity.review', 'audit.view']],
]);

it('lets a holder of customers.view_full_phone reveal even without customers.view — the route asks for exactly the one permission', function () {
    revealAs($this, $this->customer, ['customers.view_full_phone'])->assertOk();
});

it('lets an explicit deny override beat the role grant', function () {
    $user = Fx::userWith('customers.view_full_phone');
    $permission = Permission::query()->where('module', 'customers')->where('action', 'view_full_phone')->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);

    $this->actingAs($user)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertForbidden();

    expect(DB::table('phone_reveal_logs')->count())->toBe(0);
});

it('answers 403, not 404, to a viewer without the permission who asks about a customer that does not exist', function () {
    revealAs($this, 987654321, ['customers.view'])->assertForbidden();
});

it('accepts only POST', function () {
    $this->actingAs(Fx::userWith('customers.view_full_phone'))->getJson("/customers/{$this->customer->id}/reveal-phone")->assertStatus(405);
});

// ================================================================== the reveal

it('returns the full normalized phone, and only that', function () {
    $response = revealAs($this, $this->customer);

    $response->assertOk()->assertExactJson(['phone' => REVEAL_PHONE]);
    expect(array_keys($response->json()))->toBe(['phone']);
});

it('never sends the customer id, email, name or anything else about the customer', function () {
    $body = revealAs($this, $this->customer)->getContent();

    expect($body)->not->toContain('customer_id')->not->toContain('email')->not->toContain('display_name')
        ->and($body)->not->toContain('ZZ-Reveal-Name')->not->toContain('zz-reveal@example.test')
        ->and($body)->not->toContain((string) $this->customer->id);
});

it('keeps the number out of any cache: the response says no-store', function () {
    expect(revealAs($this, $this->customer)->headers->get('Cache-Control'))->toContain('no-store');
});

it('writes exactly one audit row per reveal, with who, which customer, when, from which address and browser', function () {
    $user = Fx::userWith('customers.view_full_phone');

    $this->actingAs($user)->withHeaders(['User-Agent' => 'Mozilla/5.0 (Reveal Test)'])->postJson("/customers/{$this->customer->id}/reveal-phone")->assertOk();

    $row = DB::table('phone_reveal_logs')->sole();
    expect($row->customer_id)->toBe($this->customer->id)
        ->and($row->revealed_by)->toBe($user->id)
        ->and($row->ip)->toBe('127.0.0.1')
        ->and($row->user_agent)->toBe('Mozilla/5.0 (Reveal Test)')
        // revealed_at is the database's now() (the transaction's time), so it is checked against the real clock, not travelTo
        ->and(abs(CarbonImmutable::parse($row->revealed_at)->diffInSeconds(CarbonImmutable::now('UTC'))))->toBeLessThan(120);
});

it('records the address a request came from', function () {
    $this->actingAs(Fx::userWith('customers.view_full_phone'))
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson("/customers/{$this->customer->id}/reveal-phone")->assertOk();

    expect(DB::table('phone_reveal_logs')->value('ip'))->toBe('203.0.113.7');
});

it('writes one row for every reveal: two in a row are two rows', function () {
    $user = Fx::userWith('customers.view_full_phone');

    $this->actingAs($user)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertOk();
    $this->actingAs($user)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertOk();

    expect(DB::table('phone_reveal_logs')->where('customer_id', $this->customer->id)->where('revealed_by', $user->id)->count())->toBe(2);
});

it('cuts an over-long user agent to 500 characters and still reveals', function () {
    revealAs($this, $this->customer, headers: ['User-Agent' => str_repeat('x', 900)])->assertOk();

    expect(mb_strlen((string) DB::table('phone_reveal_logs')->value('user_agent')))->toBe(500);
});

it('stores an empty string when the browser sent no user agent', function () {
    revealAs($this, $this->customer, headers: ['User-Agent' => ''])->assertOk();

    expect(DB::table('phone_reveal_logs')->value('user_agent'))->toBe('');
});

// ================================================================== a customer with nothing to reveal

it('answers 404, and writes no audit row, for a soft-deleted customer', function () {
    $this->customer->delete();

    $response = revealAs($this, $this->customer);

    $response->assertNotFound();
    expect(DB::table('phone_reveal_logs')->count())->toBe(0)->and($response->getContent())->not->toContain(REVEAL_PHONE);
});

it('answers 404, and writes no audit row, for a customer that does not exist', function () {
    revealAs($this, 987654321)->assertNotFound();

    expect(DB::table('phone_reveal_logs')->count())->toBe(0);
});

it('answers 404, and writes no audit row, for a customer that has no phone', function () {
    DB::table('customers')->where('id', $this->customer->id)->update(['phone_normalized' => '']);

    revealAs($this, $this->customer)->assertNotFound();

    expect(DB::table('phone_reveal_logs')->count())->toBe(0);
});

it('does not match a non-numeric id', function () {
    $this->actingAs(Fx::userWith('customers.view_full_phone'))->postJson('/customers/abc/reveal-phone')->assertNotFound();
});

// ================================================================== rate limit

it('allows ten reveals a minute per user and answers the eleventh with 429, without revealing or logging it', function () {
    $user = Fx::userWith('customers.view_full_phone');

    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($user)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertOk();
    }

    $eleventh = $this->actingAs($user)->postJson("/customers/{$this->customer->id}/reveal-phone");

    $eleventh->assertStatus(429);
    expect($eleventh->getContent())->not->toContain(REVEAL_PHONE)
        ->and(DB::table('phone_reveal_logs')->count())->toBe(10);
});

it('counts each user separately: one user\'s ten do not limit another', function () {
    $busy = Fx::userWith('customers.view_full_phone');
    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($busy)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertOk();
    }

    $this->actingAs($busy)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertStatus(429);
    $this->actingAs(Fx::userWith('customers.view_full_phone'))->postJson("/customers/{$this->customer->id}/reveal-phone")->assertOk();
});

it('also limits a viewer who may not reveal — Laravel runs the throttle before the permission check — so the endpoint cannot be probed', function () {
    $unpermitted = Fx::userWith('customers.view');

    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($unpermitted)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertForbidden();
    }

    $this->actingAs($unpermitted)->postJson("/customers/{$this->customer->id}/reveal-phone")->assertStatus(429);
    expect(DB::table('phone_reveal_logs')->count())->toBe(0);
});

it('gives the endpoint a throttle bucket of its own, so another inline-throttled route cannot spend its ten', function () {
    expect(collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => in_array('throttle:10,1,phone-reveal', $route->gatherMiddleware(), true))->count())->toBe(1);
});

// ================================================================== nothing is logged

it('never writes the phone or the customer\'s name to a log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
    });

    revealAs($this, $this->customer)->assertOk();
    revealAs($this, 987654321)->assertNotFound();
    $this->customer->delete();
    revealAs($this, $this->customer)->assertNotFound();

    $everything = json_encode([$logged, DB::table('phone_reveal_logs')->get()], JSON_UNESCAPED_UNICODE);

    expect($everything)->not->toContain(REVEAL_PHONE)->not->toContain('9121234567')->not->toContain('ZZ-Reveal-Name');
});
