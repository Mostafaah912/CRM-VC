<?php

declare(strict_types=1);

use App\Modules\Sync\Exceptions\WooException;
use App\Modules\Sync\Exceptions\WooFixtureNotFoundException;
use App\Modules\Sync\Exceptions\WooMalformedResponseException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooFixture;
use App\Modules\Sync\Support\WooPage;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Tests\Arch\Scanner;
use Tests\Support\WooFixtures;

/*
| P2-02 — FakeWooClient replays recorded Woo exchanges. It lives in tests/Unit on purpose:
| the Unit suite never boots Laravel, so nothing here can touch the container, database,
| Redis, config or the HTTP client. HTTP semantics (retry, Retry-After, rate limit) are
| P2-01's HttpWooClient; the parity test in tests/Feature proves the two agree.
*/

function fakeWoo(): FakeWooClient
{
    return WooFixtures::client();
}

/** @param  list<WooPage>  $pages */
function fakeWooItemIds(array $pages): array
{
    return array_merge(...array_map(fn (WooPage $p) => array_column($p->items, 'id'), $pages));
}

// --------------------------------------------------------------- contract

it('implements the WooClient contract', function () {
    expect(fakeWoo())->toBeInstanceOf(WooClient::class);
});

it('keeps the exact page()/pages() signatures of the interface', function (string $method) {
    $contract = (new ReflectionMethod(WooClient::class, $method));
    $fake = (new ReflectionMethod(FakeWooClient::class, $method));

    $shape = fn (ReflectionMethod $m) => array_map(
        fn (ReflectionParameter $p) => [$p->getName(), (string) $p->getType(), $p->isOptional()],
        $m->getParameters(),
    );

    expect($shape($fake))->toBe($shape($contract))
        ->and((string) $fake->getReturnType())->toBe((string) $contract->getReturnType());
})->with(['page', 'pages']);

it('is strictly read-only: no write operation exists on the fake', function () {
    $public = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        array_filter(
            (new ReflectionClass(FakeWooClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => ! $m->isConstructor(),
        ),
    );
    sort($public);

    expect($public)->toBe(['page', 'pages', 'requests']);
});

// ------------------------------------------------------------ determinism

it('returns an identical response for an identical request, every time', function () {
    $fake = fakeWoo();

    expect($fake->page('orders', 1))->toEqual($fake->page('orders', 1))
        ->and(fakeWoo()->page('orders', 1))->toEqual($fake->page('orders', 1));
});

it('is not mutated by repeated calls or by callers editing what they got back', function () {
    $fake = fakeWoo();
    $first = $fake->page('orders', 1);
    $items = $first->items;
    $items[0]['status'] = 'tampered';
    $items[] = ['id' => 1];

    $again = $fake->page('orders', 1);

    expect($again)->toEqual($first)
        ->and($again->items[0]['status'])->toBe('completed')
        ->and($again->items)->toHaveCount(2);
});

it('answers from the fixtures alone, independent of the clock', function () {
    $before = fakeWoo()->page('orders', 1);
    CarbonImmutable::setTestNow('2031-01-01 00:00:00');

    try {
        expect(fakeWoo()->page('orders', 1))->toEqual($before);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

// --------------------------------------------------------- fixture lookup

it('resolves a recorded request to its recorded page', function () {
    $page = fakeWoo()->page('orders', 1);

    expect(fakeWooItemIds([$page]))->toBe([5001, 5002])
        ->and($page->items[0]['billing']['phone'])->toBe('09000000001');
});

it('matches the extra filters exactly', function () {
    $fake = fakeWoo();

    expect(fakeWooItemIds([$fake->page('orders', 1, null, ['status' => 'completed'])]))->toBe([5001]);
    expect(fn () => $fake->page('orders', 1, null, ['status' => 'processing']))->toThrow(WooFixtureNotFoundException::class);
    expect(fn () => $fake->page('orders', 1, null, ['status' => 'completed', 'extra' => 'x']))->toThrow(WooFixtureNotFoundException::class);
});

it('treats a filter value the same whether it is passed as an int or a string', function () {
    $fake = new FakeWooClient([new WooFixture('orders', 1, ['customer' => '11'], 200, ['x-wp-totalpages' => '1'], [])]);

    expect($fake->page('orders', 1, null, ['customer' => 11])->items)->toBe([]);
});

it('fails loudly, never with an empty page, when nothing was recorded', function (string $endpoint, int $page) {
    expect(fn () => fakeWoo()->page($endpoint, $page))->toThrow(WooFixtureNotFoundException::class);
})->with([
    'unknown endpoint' => ['coupons', 1],
    'unrecorded page' => ['orders', 3],
]);

it('explains a miss with the endpoint, page and what IS recorded', function () {
    try {
        fakeWoo()->page('orders', 3);
    } catch (WooFixtureNotFoundException $e) {
        expect($e->getMessage())->toContain('orders')->toContain('page 3')->toContain('page 1')->toContain('page 2');

        return;
    }

    $this->fail('Expected WooFixtureNotFoundException');
});

it('names filter keys in a miss but never their values', function () {
    try {
        fakeWoo()->page('orders', 1, null, ['search' => '09121234567', 'consumer_secret' => 'super-secret-value']);
    } catch (WooFixtureNotFoundException $e) {
        expect($e->getMessage())
            ->toContain('search')
            ->not->toContain('09121234567')
            ->not->toContain('super-secret-value');

        return;
    }

    $this->fail('Expected WooFixtureNotFoundException');
});

it('does not let a fixture miss masquerade as a Woo failure the sync code may catch', function () {
    $e = new WooFixtureNotFoundException('x');

    expect($e)->not->toBeInstanceOf(WooException::class)->and($e)->toBeInstanceOf(LogicException::class);
});

it('applies the same argument checks as HttpWooClient', function (string $endpoint, int $page) {
    expect(fn () => fakeWoo()->page($endpoint, $page))->toThrow(InvalidArgumentException::class);
})->with([
    'absolute url' => ['https://evil.test/orders', 1],
    'traversal' => ['../users', 1],
    'query smuggling' => ['orders?a=b', 1],
    'empty' => ['', 1],
    'page zero' => ['orders', 0],
    'negative page' => ['orders', -1],
]);

it('records what was requested, including the frozen window, without matching on it', function () {
    $fake = fakeWoo();
    $window = SyncWindow::freeze(null, 10);

    $fake->page('orders', 1, $window, ['status' => 'completed']);
    $fake->page('orders', 2);

    $requests = $fake->requests();
    expect($requests)->toHaveCount(2)
        ->and($requests[0])->toBe(['endpoint' => 'orders', 'page' => 1, 'window' => $window, 'query' => ['status' => 'completed']])
        ->and($requests[1]['window'])->toBeNull();
});

// -------------------------------------------------------------- pagination

it('preserves the recorded page metadata', function () {
    $fake = fakeWoo();
    $one = $fake->page('orders', 1);
    $two = $fake->page('orders', 2);

    expect([$one->page, $one->totalPages, $one->total, $one->hasMore()])->toBe([1, 2, 3, true])
        ->and([$two->page, $two->totalPages, $two->total, $two->hasMore()])->toBe([2, 2, 3, false]);
});

it('walks a recorded multi-page sequence in order', function () {
    $pages = iterator_to_array(fakeWoo()->pages('orders'), preserve_keys: false);

    expect($pages)->toHaveCount(2)
        ->and(fakeWooItemIds($pages))->toBe([5001, 5002, 5003]);
});

it('represents an empty collection (TotalPages 0)', function () {
    $pages = iterator_to_array(fakeWoo()->pages('products/tags'), preserve_keys: false);

    expect($pages)->toHaveCount(1)
        ->and($pages[0]->items)->toBe([])
        ->and([$pages[0]->totalPages, $pages[0]->total, $pages[0]->hasMore()])->toBe([0, 0, false]);
});

it('represents an empty final page that the headers did not predict', function () {
    $pages = iterator_to_array(fakeWoo()->pages('products'), preserve_keys: false);

    expect($pages)->toHaveCount(2)
        ->and($pages[0]->hasMore())->toBeTrue()
        ->and($pages[1]->items)->toBe([])
        ->and($pages[1]->hasMore())->toBeFalse();
});

it('only replays the page it is asked for — iterating is the caller\'s business', function () {
    $fake = fakeWoo();

    $fake->page('orders', 2);

    expect($fake->requests())->toHaveCount(1);
});

it('fails on the first step of pages() when the endpoint was never recorded', function () {
    expect(fn () => iterator_to_array(fakeWoo()->pages('coupons')))->toThrow(WooFixtureNotFoundException::class);
});

// -------------------------------------------------------- failure fixtures

it('replays a terminal HTTP failure as the exception HttpWooClient raises', function (string $endpoint, int $status, ?string $code) {
    try {
        fakeWoo()->page($endpoint, 1);
        $this->fail('Expected WooRequestException');
    } catch (WooRequestException $e) {
        expect([$e->status, $e->attempts, $e->retryable, $e->wooCode, $e->endpoint])
            ->toBe([$status, 1, false, $code, $endpoint]);
    }
})->with([
    '401' => ['customers', 401, 'woocommerce_rest_cannot_view'],
    '404' => ['orders/999999/refunds', 404, 'woocommerce_rest_shop_order_invalid_id'],
]);

it('replays an exhausted retryable HTTP failure', function (string $endpoint, int $status) {
    try {
        fakeWoo()->page($endpoint, 1);
        $this->fail('Expected WooRequestException');
    } catch (WooRequestException $e) {
        expect([$e->status, $e->attempts, $e->retryable])->toBe([$status, 5, true]);
    }
})->with([
    '503' => ['products/101/variations', 503],
    '429' => ['products/102/variations', 429],
]);

it('replays a malformed 2xx as WooMalformedResponseException', function (string $endpoint) {
    expect(fn () => fakeWoo()->page($endpoint, 1))->toThrow(WooMalformedResponseException::class);
})->with(['products/attributes', 'products/shipping_classes']);

it('never puts a request value, header or credential in a replayed failure', function () {
    $fixture = new WooFixture('orders', 1, ['search' => '09121234567'], 403, ['Authorization' => 'Basic c2VjcmV0'], ['code' => 'x', 'message' => 'Basic c2VjcmV0']);

    try {
        (new FakeWooClient([$fixture]))->page('orders', 1, null, ['search' => '09121234567']);
    } catch (WooRequestException $e) {
        expect($e->getMessage())->not->toContain('09121234567')->not->toContain('c2VjcmV0')->not->toContain('Authorization');

        return;
    }

    $this->fail('Expected WooRequestException');
});

// ---------------------------------------------------- fixture definitions

it('rejects a fixture definition that is not usable', function (array $definition) {
    WooFixture::fromArray($definition);
})->with([
    'no request' => [['response' => ['status' => 200, 'headers' => [], 'body' => []]]],
    'no response' => [['request' => ['endpoint' => 'orders', 'page' => 1, 'query' => []]]],
    'bad endpoint' => [['request' => ['endpoint' => '../x', 'page' => 1, 'query' => []], 'response' => ['status' => 200, 'headers' => [], 'body' => []]]],
    'page zero' => [['request' => ['endpoint' => 'orders', 'page' => 0, 'query' => []], 'response' => ['status' => 200, 'headers' => [], 'body' => []]]],
    'bad status' => [['request' => ['endpoint' => 'orders', 'page' => 1, 'query' => []], 'response' => ['status' => 42, 'headers' => [], 'body' => []]]],
    'zero attempts' => [['request' => ['endpoint' => 'orders', 'page' => 1, 'query' => []], 'response' => ['status' => 503, 'headers' => [], 'body' => [], 'attempts' => 0]]],
    'nested query' => [['request' => ['endpoint' => 'orders', 'page' => 1, 'query' => ['a' => ['b']]], 'response' => ['status' => 200, 'headers' => [], 'body' => []]]],
])->throws(InvalidArgumentException::class);

it('refuses two fixtures for the same request rather than pick one', function () {
    $a = new WooFixture('orders', 1, [], 200, ['X-WP-TotalPages' => '1'], []);
    $b = new WooFixture('orders', 1, [], 200, ['X-WP-TotalPages' => '1'], [['id' => 1]]);

    new FakeWooClient([$a, $b]);
})->throws(LogicException::class, 'orders');

it('reads headers case-insensitively, as HTTP does', function () {
    $fake = new FakeWooClient([new WooFixture('orders', 1, [], 200, ['x-wp-totalpages' => '4', 'X-WP-TOTAL' => '7'], [['id' => 1]])]);

    $page = $fake->page('orders', 1);

    expect([$page->totalPages, $page->total])->toBe([4, 7]);
});

// --------------------------------------------------------------- isolation

it('contains no way to reach the network, Redis, the database, config, the clock or randomness', function () {
    $files = [
        Scanner::root().'/app/Modules/Sync/Services/FakeWooClient.php',
        Scanner::root().'/app/Modules/Sync/Support/WooFixture.php',
    ];

    expect(array_map('is_file', $files))->each->toBeTrue();
    expect(Scanner::violations($files, [
        '/\bIlluminate\\\\/',
        '/\bGuzzleHttp\\\\/',
        '/\b(Http|Redis|DB|Cache|Log|Config|Storage|Date|Carbon)::/',
        '/\bcurl_\w+\s*\(/i',
        '/\b(fsockopen|stream_socket_client|file_get_contents|fopen)\s*\(/',
        '/\b(config|app|now|today|resolve|env)\s*\(/',
        '/\b(rand|mt_rand|random_int|random_bytes|uniqid|microtime|time|date)\s*\(/',
        '/->(post|put|patch|delete|send)\s*\(/',
    ]))->toBe([]);
});

it('is only ever bound in tests, never by the application', function () {
    $hits = Scanner::violations(
        Scanner::phpFiles(['app/Providers', 'bootstrap', 'routes', 'config', 'app/Http', 'app/Console']),
        ['/FakeWooClient/'],
    );

    expect($hits)->toBe([]);
});

// ------------------------------------------------------------ fixture hygiene

it('keeps the recorded fixtures synthetic: fake phones, no credentials, no real customer data', function () {
    $raw = '';
    $phones = [];
    $collect = function (mixed $node) use (&$collect, &$phones): void {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === 'phone' && is_string($value)) {
                $phones[] = $value;
            }
            if ($key === 'email' && is_string($value)) {
                expect($value === '' || str_ends_with($value, '@example.test'))->toBeTrue();
            }
            $collect($value);
        }
    };

    $paths = glob(WooFixtures::directory().'/{,*/,*/*/}*.json', GLOB_BRACE) ?: [];
    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        $contents = (string) file_get_contents($path);
        $raw .= $contents;
        $collect(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
    }

    expect($phones)->not->toBeEmpty();
    foreach ($phones as $phone) {
        expect(PhoneNormalizer::normalize($phone))->toStartWith('989000');
    }

    expect($raw)
        ->not->toMatch('/\b[cs]k_[0-9a-f]{20,}/i')
        ->not->toMatch('/consumer_(key|secret)/i')
        ->not->toContain('Authorization')
        ->not->toMatch('/Basic [A-Za-z0-9+\/=]{8,}/');
});
