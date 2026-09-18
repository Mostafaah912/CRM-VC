<?php

declare(strict_types=1);

use App\Modules\Sync\Exceptions\WooConfigurationException;
use App\Modules\Sync\Exceptions\WooMalformedResponseException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Services\HttpWooClient;
use App\Modules\Sync\Services\RedisTokenBucket;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;

/*
| P2-01 — transport behaviour of HttpWooClient (PRD §10), tested at the Laravel
| HTTP-client boundary with Http::fake(). This is NOT the P2-02 FakeWooClient.
| Http::preventStrayRequests() guarantees nothing here can reach a real store.
*/

const WOO_TEST_KEY = 'test-consumer-key';
const WOO_TEST_SECRET = 'test-consumer-secret';

/** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
$GLOBALS['woo_logs'] = [];

beforeEach(function () {
    config([
        'woo.base_url' => 'https://woo.test',
        'woo.key' => WOO_TEST_KEY,
        'woo.secret' => WOO_TEST_SECRET,
        'woo.version' => 'wc/v3',
        'woo.timeout' => 30,
        'woo.per_page' => 50,
        'woo.max_retries' => 4,
        'woo.retry_backoff_seconds' => [2, 8, 30, 120],
        'woo.rate_limit_per_minute' => 90,
        'logging.default' => 'null',
    ]);

    Redis::del(RedisTokenBucket::KEY);
    Http::preventStrayRequests();
    Sleep::fake();
    $this->freezeTime();

    $GLOBALS['woo_logs'] = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $GLOBALS['woo_logs'][] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
    });
});

afterEach(fn () => Redis::del(RedisTokenBucket::KEY));

/** Feed Http::fake a script: each entry is a response promise or a Throwable to throw. */
function wooScript(array $script): void
{
    Http::fake(function (Request $request) use (&$script) {
        $next = array_shift($script);

        if ($next === null) {
            throw new RuntimeException('Unexpected extra request to '.$request->url());
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    });
}

/** A Woo collection response with the pagination headers Woo sends. */
function wooPage(array $items, int $totalPages = 1, ?int $total = null): mixed
{
    return Http::response($items, 200, [
        'X-WP-TotalPages' => (string) $totalPages,
        'X-WP-Total' => (string) ($total ?? count($items)),
    ]);
}

function wooClient(): WooClient
{
    return app(WooClient::class);
}

function wooRequests(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0])->values()->all();
}

/** @return array<string, string> */
function wooQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

// ---------------------------------------------------------------- success

it('performs a GET against the versioned Woo REST base and returns the page', function () {
    wooScript([wooPage([['id' => 1], ['id' => 2]], totalPages: 3, total: 120)]);

    $page = wooClient()->page('orders', 1);

    expect($page)->toBeInstanceOf(WooPage::class)
        ->and($page->items)->toBe([['id' => 1], ['id' => 2]])
        ->and($page->page)->toBe(1)
        ->and($page->totalPages)->toBe(3)
        ->and($page->total)->toBe(120)
        ->and($page->hasMore())->toBeTrue();

    $requests = wooRequests();
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->method())->toBe('GET')
        ->and(parse_url($requests[0]->url(), PHP_URL_HOST))->toBe('woo.test')
        ->and(parse_url($requests[0]->url(), PHP_URL_PATH))->toBe('/wp-json/wc/v3/orders')
        ->and(wooQuery($requests[0]))->toBe(['page' => '1', 'per_page' => '50']);
});

it('reports the last page as having no more', function () {
    wooScript([wooPage([['id' => 9]], totalPages: 3)]);

    expect(wooClient()->page('orders', 3)->hasMore())->toBeFalse();
});

// -------------------------------------------------- auth / configuration

it('authenticates with HTTP Basic and never puts credentials in the query string', function () {
    wooScript([wooPage([])]);

    wooClient()->page('orders', 1);

    $request = wooRequests()[0];
    expect($request->header('Authorization'))->toBe(['Basic '.base64_encode(WOO_TEST_KEY.':'.WOO_TEST_SECRET)])
        ->and($request->url())->not->toContain(WOO_TEST_KEY)
        ->and($request->url())->not->toContain(WOO_TEST_SECRET)
        ->and($request->url())->not->toContain('consumer_');
});

it('takes per_page from config', function () {
    config(['woo.per_page' => 25]);
    wooScript([wooPage([])]);

    wooClient()->page('orders', 1);

    expect(wooQuery(wooRequests()[0])['per_page'])->toBe('25');
});

it('refuses to build a client without complete credentials', function (string $key, ?string $value) {
    config([$key => $value]);

    wooClient();
})->with([
    'no base url' => ['woo.base_url', null],
    'empty base url' => ['woo.base_url', ''],
    'no key' => ['woo.key', null],
    'no secret' => ['woo.secret', ''],
])->throws(WooConfigurationException::class);

it('refuses a plain-http base URL — Basic auth is only allowed over HTTPS', function () {
    config(['woo.base_url' => 'http://woo.test']);

    wooClient();
})->throws(WooConfigurationException::class, 'HTTPS');

it('does not leak the secret through a configuration error', function () {
    config(['woo.base_url' => '']);

    try {
        wooClient();
    } catch (WooConfigurationException $e) {
        expect($e->getMessage())->not->toContain(WOO_TEST_SECRET)->not->toContain(WOO_TEST_KEY);

        return;
    }

    $this->fail('Expected WooConfigurationException');
});

it('only accepts relative Woo endpoints, so credentials can never be sent to another host', function (string $endpoint) {
    Http::fake();

    expect(fn () => wooClient()->page($endpoint, 1))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'absolute url' => 'https://evil.test/orders',
    'protocol-relative' => '//evil.test/orders',
    'traversal' => '../../wp/v2/users',
    'query smuggling' => 'orders?consumer_secret=x',
    'empty' => '',
]);

// ------------------------------------------------------ frozen cursor

it('sends the frozen window on every page and never lets modified_before drift', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    $window = SyncWindow::freeze(CarbonImmutable::parse('2026-03-01 09:30:00', 'UTC'), overlapMinutes: 10);

    $scripted = [wooPage([['id' => 1]], 3), wooPage([['id' => 2]], 3), wooPage([['id' => 3]], 3)];
    Http::fake(function (Request $request) use (&$scripted) {
        // time passes while the job paginates — the boundary must not follow it
        test()->travel(5)->minutes();

        return array_shift($scripted);
    });

    $pages = iterator_to_array(wooClient()->pages('orders', $window), preserve_keys: false);

    expect($pages)->toHaveCount(3);
    $requests = wooRequests();
    expect($requests)->toHaveCount(3);

    foreach ($requests as $i => $request) {
        expect(wooQuery($request))->toBe([
            'modified_after' => '2026-03-01T09:20:00',
            'modified_before' => '2026-03-01T10:00:00',
            'orderby' => 'modified',
            'order' => 'asc',
            'dates_are_gmt' => 'true',
            'page' => (string) ($i + 1),
            'per_page' => '50',
        ]);
    }
});

it('does not add window parameters when no window is given', function () {
    wooScript([wooPage([])]);

    wooClient()->page('products/categories', 1);

    expect(wooQuery(wooRequests()[0]))->toBe(['page' => '1', 'per_page' => '50']);
});

it('lets callers add filters but never override pagination or the frozen window', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    $window = SyncWindow::freeze(null, overlapMinutes: 10);
    wooScript([wooPage([])]);

    wooClient()->page('orders', 2, $window, [
        'status' => 'completed',
        'page' => 99,
        'per_page' => 999,
        'modified_before' => '2099-01-01T00:00:00',
        'orderby' => 'id',
    ]);

    $query = wooQuery(wooRequests()[0]);
    expect($query['status'])->toBe('completed')
        ->and($query['page'])->toBe('2')
        ->and($query['per_page'])->toBe('50')
        ->and($query['modified_before'])->toBe('2026-03-01T10:00:00')
        ->and($query['orderby'])->toBe('modified');
});

// -------------------------------------------------------- pagination

it('stops after X-WP-TotalPages without requesting a page beyond it', function () {
    wooScript([wooPage([['id' => 1]], 2), wooPage([['id' => 2]], 2)]);

    $items = [];
    foreach (wooClient()->pages('orders') as $page) {
        array_push($items, ...$page->items);
    }

    expect($items)->toBe([['id' => 1], ['id' => 2]])
        ->and(wooRequests())->toHaveCount(2);
});

it('stops immediately on an empty first page (Woo reports TotalPages 0)', function () {
    wooScript([wooPage([], totalPages: 0, total: 0)]);

    $pages = iterator_to_array(wooClient()->pages('orders'), preserve_keys: false);

    expect($pages)->toHaveCount(1)
        ->and($pages[0]->items)->toBe([])
        ->and(wooRequests())->toHaveCount(1);
});

it('stops on an empty page even if the headers still promise more', function () {
    wooScript([wooPage([['id' => 1]], 5), wooPage([], 5)]);

    $pages = iterator_to_array(wooClient()->pages('orders'), preserve_keys: false);

    expect($pages)->toHaveCount(2)
        ->and(wooRequests())->toHaveCount(2);
});

// ------------------------------------------------------------- retry

it('retries HTTP 429 and honours Retry-After given in seconds', function () {
    wooScript([Http::response('', 429, ['Retry-After' => '7']), wooPage([['id' => 1]])]);

    $page = wooClient()->page('orders', 1);

    expect($page->items)->toBe([['id' => 1]])
        ->and(wooRequests())->toHaveCount(2);
    Sleep::assertSequence([Sleep::for(7)->seconds()]);
});

it('honours Retry-After given as an HTTP date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    wooScript([
        Http::response('', 429, ['Retry-After' => 'Sun, 01 Mar 2026 10:00:45 GMT']),
        wooPage([]),
    ]);

    wooClient()->page('orders', 1);

    Sleep::assertSequence([Sleep::for(45)->seconds()]);
});

it('falls back to the PRD backoff when Retry-After is missing or unusable', function (?string $retryAfter) {
    $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];
    wooScript([Http::response('', 429, $headers), wooPage([])]);

    wooClient()->page('orders', 1);

    Sleep::assertSequence([Sleep::for(2)->seconds()]);
})->with([
    'missing' => [null],
    'garbage' => ['soon'],
    'zero' => ['0'],
    'negative' => ['-5'],
    'empty' => [''],
]);

it('does not use Retry-After for statuses other than 429', function () {
    wooScript([Http::response('', 503, ['Retry-After' => '99']), wooPage([])]);

    wooClient()->page('orders', 1);

    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('retries transient server statuses and then succeeds', function (int $status) {
    wooScript([Http::response('', $status), wooPage([['id' => 1]])]);

    $page = wooClient()->page('orders', 1);

    expect($page->items)->toBe([['id' => 1]])
        ->and(wooRequests())->toHaveCount(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
})->with([500, 502, 503, 504]);

it('retries a connection failure', function () {
    wooScript([new ConnectionException('cURL error 7: Failed to connect to woo.test port 443'), wooPage([])]);

    $page = wooClient()->page('orders', 1);

    expect($page->items)->toBe([]);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('retries a timeout', function () {
    wooScript([new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'), wooPage([])]);

    $page = wooClient()->page('orders', 1);

    expect($page->items)->toBe([]);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('walks the PRD backoff ladder 2s, 8s, 30s, 120s and stops after 4 retries', function () {
    wooScript(array_map(fn () => Http::response('', 503), range(1, 5)));

    try {
        wooClient()->page('orders', 1);
        $this->fail('Expected WooRequestException');
    } catch (WooRequestException $e) {
        expect($e->status)->toBe(503)
            ->and($e->attempts)->toBe(5)
            ->and($e->retryable)->toBeTrue()
            ->and($e->endpoint)->toBe('orders');
    }

    Sleep::assertSequence([
        Sleep::for(2)->seconds(),
        Sleep::for(8)->seconds(),
        Sleep::for(30)->seconds(),
        Sleep::for(120)->seconds(),
    ]);
    expect(wooRequests())->toHaveCount(5);
});

it('gives up after 4 retries on connection failures too, and says so', function () {
    wooScript(array_map(fn () => new ConnectionException('cURL error 28: Operation timed out'), range(1, 5)));

    try {
        wooClient()->page('orders', 1);
        $this->fail('Expected WooRequestException');
    } catch (WooRequestException $e) {
        expect($e->status)->toBeNull()
            ->and($e->attempts)->toBe(5)
            ->and($e->retryable)->toBeTrue();
    }

    Sleep::assertSleptTimes(4);
});

it('spends 429 retries from the same budget of 4', function () {
    wooScript(array_map(fn () => Http::response('', 429, ['Retry-After' => '1']), range(1, 5)));

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooRequestException::class);

    Sleep::assertSleptTimes(4);
    expect(wooRequests())->toHaveCount(5);
});

it('respects config(woo.max_retries)', function () {
    config(['woo.max_retries' => 2]);
    wooScript(array_map(fn () => Http::response('', 500), range(1, 3)));

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooRequestException::class);

    expect(wooRequests())->toHaveCount(3);
    Sleep::assertSleptTimes(2);
});

// ---------------------------------------------------- terminal errors

it('fails at once on terminal statuses without retrying', function (int $status) {
    wooScript([Http::response(['code' => 'woocommerce_rest_cannot_view', 'message' => 'Sorry'], $status)]);

    try {
        wooClient()->page('orders', 1);
        $this->fail('Expected WooRequestException');
    } catch (WooRequestException $e) {
        expect($e->status)->toBe($status)
            ->and($e->attempts)->toBe(1)
            ->and($e->retryable)->toBeFalse()
            ->and($e->getMessage())->toContain((string) $status)->toContain('orders');
    }

    expect(wooRequests())->toHaveCount(1);
    Sleep::assertNeverSlept();
})->with([400, 401, 403, 404]);

it('treats any status the PRD does not list as retryable as terminal', function (int $status) {
    wooScript([Http::response('', $status)]);

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooRequestException::class);

    expect(wooRequests())->toHaveCount(1);
    Sleep::assertNeverSlept();
})->with([301, 302, 405, 409, 422, 501, 505]);

// ------------------------------------------------------------ logging

it('logs each retry as a warning and a terminal failure as an error', function () {
    wooScript([Http::response('', 503), Http::response(['code' => 'woocommerce_rest_cannot_view'], 401)]);

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooRequestException::class);

    $levels = array_column($GLOBALS['woo_logs'], 'level');
    expect($levels)->toBe(['warning', 'error']);

    $retry = $GLOBALS['woo_logs'][0]['context'];
    expect($retry)->toMatchArray(['endpoint' => 'orders', 'status' => 503, 'attempt' => 1, 'delay_seconds' => 2]);

    $terminal = $GLOBALS['woo_logs'][1]['context'];
    expect($terminal)->toMatchArray(['endpoint' => 'orders', 'status' => 401, 'attempts' => 2, 'woo_code' => 'woocommerce_rest_cannot_view']);
});

it('never logs or throws credentials, auth headers, or query values', function () {
    wooScript([Http::response(['code' => 'x', 'message' => 'no'], 500), Http::response('', 403)]);

    try {
        wooClient()->page('orders', 1, null, ['search' => '09121234567']);
    } catch (WooRequestException $e) {
        $everything = $e->getMessage().json_encode($GLOBALS['woo_logs']);

        expect($everything)
            ->not->toContain(WOO_TEST_SECRET)
            ->not->toContain(WOO_TEST_KEY)
            ->not->toContain(base64_encode(WOO_TEST_KEY.':'.WOO_TEST_SECRET))
            ->not->toContain('Authorization')
            ->not->toContain('09121234567');

        return;
    }

    $this->fail('Expected WooRequestException');
});

it('does not turn a failure into a fake success', function () {
    wooScript(array_map(fn () => Http::response('', 500), range(1, 5)));

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooRequestException::class);
});

// ---------------------------------------------------- malformed data

it('rejects a 2xx response that is not valid JSON, without retrying', function () {
    wooScript([Http::response('<html>Maintenance</html>', 200, ['X-WP-TotalPages' => '1'])]);

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooMalformedResponseException::class);

    expect(wooRequests())->toHaveCount(1);
    Sleep::assertNeverSlept();
});

it('rejects a collection response that is not a list of objects', function (mixed $body) {
    wooScript([Http::response($body, 200, ['X-WP-TotalPages' => '1'])]);

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooMalformedResponseException::class);
})->with([
    'object' => [['code' => 'rest_no_route']],
    'scalar items' => [[1, 2, 3]],
    'json null' => [null],
]);

it('rejects a collection response without usable pagination headers rather than guess', function (array $headers) {
    wooScript([Http::response([['id' => 1]], 200, $headers)]);

    expect(fn () => wooClient()->page('orders', 1))->toThrow(WooMalformedResponseException::class);
})->with([
    'missing' => [[]],
    'non numeric' => [['X-WP-TotalPages' => 'many']],
    'negative' => [['X-WP-TotalPages' => '-1']],
]);

it('rejects a page that holds items beyond the reported total pages', function () {
    wooScript([wooPage([['id' => 1]], totalPages: 1)]);

    expect(fn () => wooClient()->page('orders', 2))->toThrow(WooMalformedResponseException::class);
});

// ---------------------------------------------------------- read-only

it('exposes no way to write to WooCommerce', function () {
    $public = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(WooClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($public);

    expect($public)->toBe(['page', 'pages']);

    $concrete = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        array_filter(
            (new ReflectionClass(HttpWooClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => ! $m->isConstructor(),
        ),
    );
    sort($concrete);

    expect($concrete)->toBe(['page', 'pages']);
});

it('only ever sends GET requests, including on retries', function () {
    wooScript([Http::response('', 500), wooPage([['id' => 1]], 2), wooPage([['id' => 2]], 2)]);

    iterator_to_array(wooClient()->pages('orders'));

    foreach (wooRequests() as $request) {
        expect($request->method())->toBe('GET');
    }
});

it('contains no write-verb HTTP call anywhere in the Sync module', function () {
    $hits = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Sync'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/->(post|put|patch|delete|send)\s*\(|Http::(post|put|patch|delete)\b|CURLOPT_POST|CURLOPT_CUSTOMREQUEST/', (string) file_get_contents($file->getPathname()))) {
            $hits[] = $file->getPathname();
        }
    }

    expect($hits)->toBe([]);
});

// --------------------------------------------- rate limiting + transport

it('spends one rate-limit token per HTTP attempt: the 91st request within a minute waits', function () {
    Http::fake(fn () => wooPage([]));

    foreach (range(1, 90) as $ignored) {
        wooClient()->page('orders', 1);
    }
    Sleep::assertNeverSlept();

    wooClient()->page('orders', 1);

    Sleep::assertSequence([Sleep::for(667)->milliseconds()]);
});

it('also rate-limits retries, not just first attempts', function () {
    // 89 successful requests, then attempt 90 fails and its retry is attempt 91
    wooScript([...array_map(fn () => wooPage([]), range(1, 89)), Http::response('', 503), wooPage([])]);
    foreach (range(1, 89) as $ignored) {
        wooClient()->page('orders', 1);
    }

    wooClient()->page('orders', 1);

    Sleep::assertSequence([
        Sleep::for(2)->seconds(),        // PRD backoff before the retry…
        Sleep::for(667)->milliseconds(), // …and the bucket, empty after 90 requests, still gates it
    ]);
});

it('never sends a request when the rate limiter cannot reach Redis', function () {
    config(['database.redis.dead' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]]);
    app()->forgetInstance('redis'); // the manager caches its connection config
    Redis::clearResolvedInstances();
    app()->bind(RedisTokenBucket::class, fn () => new RedisTokenBucket(perMinute: 90, connection: 'dead'));
    Http::fake();

    expect(fn () => wooClient()->page('orders', 1))->toThrow(RedisException::class);

    Http::assertNothingSent();
});
