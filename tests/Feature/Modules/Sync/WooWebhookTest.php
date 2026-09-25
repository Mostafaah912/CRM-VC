<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Jobs\SyncEntityJob;
use App\Modules\Sync\Models\WooWebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\WooWebhookSignature;

/*
| P2-09 — POST /webhooks/woo. Verify (secret, IP allowlist, base64 HMAC-SHA256 of the raw body) -> route the topic ->
| record the delivery id (UNIQUE = dedupe) and queue ONE incremental orders SyncEntityJob on `critical`, in one
| transaction -> 200 at once. The body is never read as data or logged; only three headers are read. Woo's payload is not
| trusted for data: the job re-reads Woo through the API. Requests here are raw calls with Woo's real header names.
*/

const HOOK_SECRET = 'whsec-webhook-test-secret';
const HOOK_BODY_MARKER = 'PII-MARKER-09121234567';

$GLOBALS['hook_logs'] = [];
$GLOBALS['hook_delivery_counter'] = 0;

beforeEach(function () {
    config(['logging.default' => 'null', 'woo.webhook_secret' => HOOK_SECRET, 'woo.webhook_allowed_ips' => '', 'trustedproxy.proxies' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC'));
    Queue::fake();
    $GLOBALS['hook_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $GLOBALS['hook_logs'][] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
    });
});

/**
 * One delivery as Woo sends it. A null header value removes that header; keys override the defaults.
 *
 * @param  array<string, string|null>  $server
 */
function hookCall(string $body = '{"id":5001}', array $server = []): TestResponse
{
    $defaults = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.updated',
        'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => md5('delivery-'.++$GLOBALS['hook_delivery_counter']),
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => WooWebhookSignature::for($body, HOOK_SECRET),
        'REMOTE_ADDR' => '203.0.113.10',
    ];

    return test()->call('POST', '/webhooks/woo', [], [], [], array_filter([...$defaults, ...$server], fn (?string $v) => $v !== null), $body);
}

function hookAssertNothingHappened(): void
{
    expect(WooWebhookDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
}

/** The queued job's own uniqueness lock, cleared so that only the delivery id can stop a second dispatch. */
function hookReleaseJobLock(): void
{
    Cache::lock(UniqueLock::getKey(new SyncEntityJob(SyncEntity::Orders)))->forceRelease();
}

function hookLogText(): string
{
    return json_encode($GLOBALS['hook_logs'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

// ================================================================== accepted

it('accepts a correctly signed delivery: 200, one recorded delivery, one incremental orders job on the critical queue', function () {
    $response = hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-1001']);

    $response->assertOk()->assertExactJson(['status' => 'accepted']);
    $delivery = WooWebhookDelivery::sole();
    expect($delivery->woo_delivery_id)->toBe('d-1001')
        ->and($delivery->topic->value)->toBe('order.updated')
        ->and($delivery->received_at->utc()->format('Y-m-d\TH:i:s'))->toBe('2026-06-01T12:00:00');
    Queue::assertPushed(SyncEntityJob::class, 1);
    Queue::assertPushedOn('critical', SyncEntityJob::class, fn (SyncEntityJob $job) => $job->entity === SyncEntity::Orders && $job->mode === SyncMode::Incremental);
});

it('queues nothing but the orders sync job', function () {
    hookCall();

    expect(array_keys(Queue::pushedJobs()))->toBe([SyncEntityJob::class]);
});

it('sets no cookie and starts no session: it is not part of the web group', function () {
    $response = hookCall();

    expect($response->headers->getCookies())->toBe([]);

    $middleware = Route::getRoutes()->getByName('webhooks.woo')?->gatherMiddleware() ?? ['<no route>'];
    expect($middleware)->not->toContain('web')
        ->and(collect($middleware)->filter(fn ($m) => is_string($m) && str_contains($m, 'Csrf'))->all())->toBe([]);
});

it('is a POST-only endpoint at exactly /webhooks/woo', function () {
    test()->get('/webhooks/woo')->assertStatus(405);
    test()->post('/api/webhooks/woo')->assertNotFound();
});

// ================================================================== authenticity: 401

it('rejects a tampered body with 401, and records and queues nothing', function () {
    $signed = '{"id":5001}';

    hookCall('{"id":5002}', ['HTTP_X_WC_WEBHOOK_SIGNATURE' => WooWebhookSignature::for($signed, HOOK_SECRET)])
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized']);

    hookAssertNothingHappened();
});

it('rejects a signature made with another secret, a missing signature and an empty one', function (?string $signature) {
    hookCall('{"id":5001}', ['HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature])->assertUnauthorized();

    hookAssertNothingHappened();
})->with([
    'another secret' => [WooWebhookSignature::for('{"id":5001}', 'not-the-secret')],
    'missing' => [null],
    'empty' => [''],
]);

it('requires the base64 form: the hex digest of the right HMAC is rejected', function () {
    hookCall('{"id":5001}', ['HTTP_X_WC_WEBHOOK_SIGNATURE' => hash_hmac('sha256', '{"id":5001}', HOOK_SECRET)])->assertUnauthorized();

    hookAssertNothingHappened();
});

it('signs the raw bytes: a Persian body verifies, the same JSON re-encoded does not', function () {
    $raw = "{\n  \"note\": \"سفارش آزمایشی\",\n  \"id\": 5001\n}";
    $reencoded = json_encode(json_decode($raw, true), JSON_UNESCAPED_UNICODE);

    hookCall($raw)->assertOk();
    hookCall($reencoded, ['HTTP_X_WC_WEBHOOK_SIGNATURE' => WooWebhookSignature::for($raw, HOOK_SECRET)])->assertUnauthorized();
});

it('fails closed without a secret, even for a signature made with an empty key', function (mixed $secret) {
    config(['woo.webhook_secret' => $secret]);

    hookCall('{"id":5001}', ['HTTP_X_WC_WEBHOOK_SIGNATURE' => WooWebhookSignature::for('{"id":5001}', '')])->assertUnauthorized();
    hookCall()->assertUnauthorized();

    hookAssertNothingHappened();
})->with(['null' => [null], 'empty' => ['']]);

it('answers every kind of rejection the same way, never saying why', function () {
    $bodies = [];
    $bodies[] = hookCall('{"id":1}', ['HTTP_X_WC_WEBHOOK_SIGNATURE' => 'wrong'])->getContent();
    config(['woo.webhook_allowed_ips' => '198.51.100.1']);
    $bodies[] = hookCall()->getContent();
    config(['woo.webhook_secret' => '']);
    $bodies[] = hookCall()->getContent();

    expect(array_unique($bodies))->toBe(['{"message":"Unauthorized"}']);
});

// ================================================================== authenticity: source IP

it('checks the source IP once an allowlist is set: a listed IP passes, an unlisted one fails even with a valid signature', function (string $ip, mixed $list, int $status) {
    config(['woo.webhook_allowed_ips' => $list]);

    hookCall(server: ['REMOTE_ADDR' => $ip])->assertStatus($status);

    expect(WooWebhookDelivery::count())->toBe($status === 200 ? 1 : 0);
})->with([
    'listed' => ['203.0.113.10', '203.0.113.10', 200],
    'listed among several' => ['203.0.113.10', '198.51.100.7, 203.0.113.10', 200],
    'inside a CIDR range' => ['203.0.113.77', '203.0.113.0/24', 200],
    'IPv6 inside a range' => ['2001:db8::1', '2001:db8::/32', 200],
    'not listed' => ['203.0.113.11', '203.0.113.10', 401],
    'a garbage list denies everyone' => ['203.0.113.10', 'not-an-ip', 401],
    'a blank list checks nothing' => ['203.0.113.11', ' , ', 200],
]);

it('reads the client IP from X-Forwarded-For only when the connecting proxy is a trusted one', function () {
    config(['woo.webhook_allowed_ips' => '203.0.113.10']);
    $viaProxy = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10'];

    hookCall(server: $viaProxy)->assertUnauthorized(); // nobody is trusted by default: the proxy's own address is used

    config(['trustedproxy.proxies' => '10.0.0.1']);
    hookCall(server: $viaProxy)->assertOk();

    hookCall(server: ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10'])->assertUnauthorized(); // a proxy that is not trusted cannot vouch for an IP
});

// ================================================================== topic routing

it('accepts the three order topics in both spellings and records the canonical one', function (string $header, string $canonical) {
    hookCall(server: ['HTTP_X_WC_WEBHOOK_TOPIC' => $header])->assertOk()->assertExactJson(['status' => 'accepted']);

    expect(WooWebhookDelivery::sole()->topic->value)->toBe($canonical);
    Queue::assertPushedOn('critical', SyncEntityJob::class, fn (SyncEntityJob $job) => $job->entity === SyncEntity::Orders && $job->mode === SyncMode::Incremental);
})->with([
    'created' => ['order.created', 'order.created'],
    'updated' => ['order.updated', 'order.updated'],
    'deleted' => ['order.deleted', 'order.deleted'],
    'prefixed created' => ['woocommerce.order.created', 'order.created'],
    'prefixed updated' => ['woocommerce.order.updated', 'order.updated'],
    'prefixed deleted' => ['woocommerce.order.deleted', 'order.deleted'],
]);

it('accepts and ignores every other topic: 200, nothing recorded, nothing queued', function (?string $header) {
    hookCall(server: ['HTTP_X_WC_WEBHOOK_TOPIC' => $header])->assertOk()->assertExactJson(['status' => 'ignored']);

    hookAssertNothingHappened();
})->with([
    'a ping without a topic header' => [null],
    'empty' => [''],
    'product' => ['product.updated'],
    'coupon' => ['coupon.created'],
    'restored order' => ['order.restored'],
    'refund' => ['refund.created'],
    'prefixed refund' => ['woocommerce.refund.created'],
    'another prefix' => ['wc.order.created'],
    'prefix twice' => ['woocommerce.woocommerce.order.created'],
]);

// ================================================================== deduplication

it('answers a repeated delivery id with 200, and neither records nor queues it again', function () {
    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-77'])->assertOk()->assertExactJson(['status' => 'accepted']);
    hookReleaseJobLock(); // so only the delivery id can stop the second dispatch

    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-77'])->assertOk()->assertExactJson(['status' => 'duplicate']);

    expect(WooWebhookDelivery::count())->toBe(1);
    Queue::assertPushed(SyncEntityJob::class, 1);
});

it('keys the dedupe on the delivery id alone, not on the topic', function () {
    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-88', 'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created'])->assertOk();
    hookReleaseJobLock();

    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-88', 'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.deleted'])->assertOk()->assertExactJson(['status' => 'duplicate']);

    expect(WooWebhookDelivery::count())->toBe(1);
});

it('treats a delivery recorded earlier as a duplicate, whatever process recorded it', function () {
    WooWebhookDelivery::create(['topic' => 'order.updated', 'woo_delivery_id' => 'd-99', 'received_at' => CarbonImmutable::now('UTC')->subDay()]);

    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-99'])->assertOk()->assertExactJson(['status' => 'duplicate']);

    expect(WooWebhookDelivery::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('records every distinct delivery, and lets the job\'s own uniqueness coalesce a burst into one queued job', function () {
    foreach (['a', 'b', 'c'] as $id) {
        hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => "burst-{$id}"])->assertOk()->assertExactJson(['status' => 'accepted']);
    }

    expect(WooWebhookDelivery::count())->toBe(3);
    Queue::assertPushed(SyncEntityJob::class, 1);
});

it('queues a job for each distinct delivery when none is waiting in the queue', function () {
    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'e-1'])->assertOk();
    hookReleaseJobLock();
    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'e-2'])->assertOk();

    Queue::assertPushed(SyncEntityJob::class, 2);
});

it('takes the delivery id as Woo really sends it — a 32-character hash — as well as integers, UUIDs and 64-character ids', function (string $id) {
    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $id])->assertOk()->assertExactJson(['status' => 'accepted']);

    expect(WooWebhookDelivery::sole()->woo_delivery_id)->toBe($id);
})->with([
    'an md5 hash' => ['5d41402abc4b2a76b9719d911017c592'],
    'an integer' => ['4821'],
    'a uuid' => ['3f2b8c1e-9a4d-4c5e-8f00-1a2b3c4d5e6f'],
    'a 64-character id' => [str_repeat('a', 64)],
    'dots and underscores' => ['delivery_1.2-3'],
]);

it('ignores a signed delivery with no usable id — 200, nothing recorded or queued, a warning without the body', function (?string $id) {
    hookCall(HOOK_BODY_MARKER, ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $id])->assertOk()->assertExactJson(['status' => 'ignored']);

    hookAssertNothingHappened();
    $warnings = array_values(array_filter($GLOBALS['hook_logs'], fn (array $l) => $l['level'] === 'warning'));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context'])->toBe(['topic' => 'order.updated'])
        ->and(hookLogText())->not->toContain(HOOK_BODY_MARKER);
})->with([
    'missing' => [null],
    'empty' => [''],
    'too long' => [str_repeat('a', 65)],
    'a space' => ['has space'],
    'a semicolon' => ['a;b'],
    'non-ASCII' => ['شناسه'],
    'a path' => ['../x'],
    'a newline' => ["abc\n"],
]);

// ================================================================== atomic with the dispatch

it('rolls the delivery back and answers 500 when the queue is down, then lets Woo\'s retry through', function () {
    $real = app(Dispatcher::class);
    $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down')));

    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'r-1'])->assertStatus(500);

    expect(WooWebhookDelivery::count())->toBe(0);
    Queue::assertNothingPushed();

    $this->app->instance(Dispatcher::class, $real);
    hookCall(server: ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'r-1'])->assertOk()->assertExactJson(['status' => 'accepted']);

    expect(WooWebhookDelivery::sole()->woo_delivery_id)->toBe('r-1');
    Queue::assertPushed(SyncEntityJob::class, 1); // the failed attempt must not leave the job's uniqueness lock behind
});

// ================================================================== the body is never read, parsed or logged

it('accepts a body that is not JSON at all: the body is signed as raw bytes and never read as data', function () {
    hookCall('{"billing":{"phone":"'.HOOK_BODY_MARKER.'"}}')->assertOk()->assertExactJson(['status' => 'accepted']);
    hookCall('webhook_id='.HOOK_BODY_MARKER, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'form'])->assertOk()->assertExactJson(['status' => 'accepted']);
    hookCall('not json {{{', ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'not-json'])->assertOk()->assertExactJson(['status' => 'accepted']);
    hookCall('', ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'empty'])->assertOk()->assertExactJson(['status' => 'accepted']);
});

it('never writes the body, the secret or the signature to a log, on any path', function () {
    $body = '{"billing":{"phone":"'.HOOK_BODY_MARKER.'"}}';
    $signature = WooWebhookSignature::for($body, HOOK_SECRET);

    hookCall($body);                                                                               // accepted
    hookCall($body, ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'log-1', 'HTTP_X_WC_WEBHOOK_TOPIC' => 'product.created']); // ignored
    hookCall($body, ['HTTP_X_WC_WEBHOOK_DELIVERY_ID' => null]);                                    // no delivery id
    hookCall($body, ['HTTP_X_WC_WEBHOOK_SIGNATURE' => 'forged-'.$signature]);                       // forged
    config(['woo.webhook_secret' => '']);
    hookCall($body);                                                                               // not configured

    $log = hookLogText();
    expect($log)->not->toContain(HOOK_BODY_MARKER)->not->toContain(HOOK_SECRET)->not->toContain($signature)->not->toContain('forged-');
});

it('logs a rejection with its reason and the source IP only', function () {
    hookCall('{"id":1}', ['HTTP_X_WC_WEBHOOK_SIGNATURE' => 'wrong', 'REMOTE_ADDR' => '198.51.100.9'])->assertUnauthorized();

    $warnings = array_values(array_filter($GLOBALS['hook_logs'], fn (array $l) => $l['level'] === 'warning'));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context'])->toBe(['reason' => 'signature_invalid', 'ip' => '198.51.100.9']);
});
