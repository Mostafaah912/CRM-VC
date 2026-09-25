<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\PhoneRevealLog;
use App\Modules\Customers\Services\PhoneRevealService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P3-02 — PhoneRevealService::reveal(Customer, Request): the customer's full normalized phone, released ONLY together with its
| audit row. The row and the read are one transaction, so a number is never released without its audit row and an audit row
| never survives a reveal that failed. The service holds the checks that must not depend on the HTTP layer (a trashed or
| phone-less customer reveals nothing and audits nothing), takes who is asking from the request (never the Auth facade) and
| never logs anything.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
});

// Model events are static: a listener a test adds must not outlive it.
afterEach(fn () => PhoneRevealLog::flushEventListeners());

function revealRequest(?User $user, string $ip = '198.51.100.4', string $agent = 'UA-Service-Test'): Request
{
    $request = Request::create('/customers/1/reveal-phone', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('returns the full normalized phone and writes one audit row with the right fields', function () {
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567']);
    $user = User::factory()->create();

    $phone = app(PhoneRevealService::class)->reveal($customer, revealRequest($user));

    $log = PhoneRevealLog::sole();
    expect($phone)->toBe('989121234567')
        ->and($log->customer_id)->toBe($customer->id)
        ->and($log->revealed_by)->toBe($user->id)
        ->and($log->ip)->toBe('198.51.100.4')
        ->and($log->user_agent)->toBe('UA-Service-Test')
        ->and($log->revealed_at)->not->toBeNull();
});

it('takes who is asking from the request, not from whoever happens to be signed in', function () {
    $customer = Customer::factory()->create();
    $signedIn = User::factory()->create();
    $asking = User::factory()->create();
    $this->actingAs($signedIn);

    app(PhoneRevealService::class)->reveal($customer, revealRequest($asking));

    expect(PhoneRevealLog::sole()->revealed_by)->toBe($asking->id);
});

it('refuses a request nobody is signed in on, and releases and writes nothing', function () {
    $customer = Customer::factory()->create();

    expect(fn () => app(PhoneRevealService::class)->reveal($customer, revealRequest(null)))->toThrow(AuthenticationException::class);
    expect(PhoneRevealLog::count())->toBe(0);
});

it('reveals nothing and audits nothing for a soft-deleted customer', function () {
    $customer = Customer::factory()->create();
    $customer->delete();
    $trashed = Customer::withTrashed()->findOrFail($customer->id);

    expect(fn () => app(PhoneRevealService::class)->reveal($trashed, revealRequest(User::factory()->create())))->toThrow(NotFoundHttpException::class);
    expect(PhoneRevealLog::count())->toBe(0);
});

it('reveals nothing and audits nothing for a customer with no phone', function (?string $blank) {
    $customer = Customer::factory()->create();
    DB::table('customers')->where('id', $customer->id)->update(['phone_normalized' => $blank ?? '']);
    $fresh = Customer::findOrFail($customer->id);
    if ($blank === null) {
        $fresh->setRawAttributes([...$fresh->getAttributes(), 'phone_normalized' => null]);
    }

    expect(fn () => app(PhoneRevealService::class)->reveal($fresh, revealRequest(User::factory()->create())))->toThrow(NotFoundHttpException::class);
    expect(PhoneRevealLog::count())->toBe(0);
})->with(['empty string' => [''], 'null' => [null], 'blanks' => ['   ']]);

it('cuts an over-long user agent to 500 characters, counting characters and not bytes', function () {
    $customer = Customer::factory()->create();

    app(PhoneRevealService::class)->reveal($customer, revealRequest(User::factory()->create(), agent: str_repeat('ب', 700)));

    $stored = (string) PhoneRevealLog::sole()->user_agent;
    expect(mb_strlen($stored))->toBe(500)->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue();
});

it('keeps a user agent that already fits, exactly', function () {
    $customer = Customer::factory()->create();
    $agent = str_repeat('a', 500);

    app(PhoneRevealService::class)->reveal($customer, revealRequest(User::factory()->create(), agent: $agent));

    expect(PhoneRevealLog::sole()->user_agent)->toBe($agent);
});

it('stores an empty user agent as an empty string, not an error', function () {
    $customer = Customer::factory()->create();

    app(PhoneRevealService::class)->reveal($customer, revealRequest(User::factory()->create(), agent: ''));

    expect(PhoneRevealLog::sole()->user_agent)->toBe('');
});

// ================================================================== the transaction

it('rolls the audit row back, and releases no number, when the reveal fails after the insert', function () {
    $customer = Customer::factory()->create();
    PhoneRevealLog::created(function () {
        throw new RuntimeException('audit could not be completed');
    });

    $released = null;
    try {
        $released = app(PhoneRevealService::class)->reveal($customer, revealRequest(User::factory()->create()));
    } catch (RuntimeException) {
        // expected
    }

    expect($released)->toBeNull()->and(PhoneRevealLog::count())->toBe(0);
});

it('does not release the number when the audit insert itself fails', function () {
    $customer = Customer::factory()->create();
    // revealed_by must reference a real user: an id that does not exist violates the foreign key
    $ghost = new User;
    $ghost->id = 987654321;

    $released = null;
    try {
        $released = app(PhoneRevealService::class)->reveal($customer, revealRequest($ghost));
    } catch (Throwable) {
        // expected
    }

    expect($released)->toBeNull();
});

it('writes the audit row inside its own transaction, one level deeper than the caller', function () {
    $customer = Customer::factory()->create();
    $before = DB::transactionLevel();
    $during = null;
    PhoneRevealLog::creating(function () use (&$during) {
        $during = DB::transactionLevel();
    });

    app(PhoneRevealService::class)->reveal($customer, revealRequest(User::factory()->create()));

    expect($during)->toBe($before + 1)->and(DB::transactionLevel())->toBe($before);
});

// ================================================================== nothing is logged

it('never writes the phone or a name to a log, on success or refusal', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
    });
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567', 'display_name' => 'ZZ-Service-Name']);
    $user = User::factory()->create();

    app(PhoneRevealService::class)->reveal($customer, revealRequest($user));
    $customer->delete();
    try {
        app(PhoneRevealService::class)->reveal(Customer::withTrashed()->findOrFail($customer->id), revealRequest($user));
    } catch (NotFoundHttpException $e) {
        $logged[] = $e->getMessage();
    }

    expect(json_encode($logged, JSON_UNESCAPED_UNICODE))->not->toContain('9121234567')->not->toContain('ZZ-Service-Name');
});
