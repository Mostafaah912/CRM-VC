<?php

declare(strict_types=1);

use App\Modules\Sync\Exceptions\WooException;
use App\Modules\Sync\Exceptions\WooMalformedResponseException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Services\HttpWooClient;
use App\Modules\Sync\Services\RedisTokenBucket;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\WooFixture;
use App\Modules\Sync\Support\WooPage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Tests\Support\WooFixtures;

/*
| P2-02 guard rail: FakeWooClient must never drift from the real client. Every recorded
| exchange is replayed through HttpWooClient (at the Http::fake boundary — no network) and
| through FakeWooClient; both must end in the same page or the same exception facts.
| If HttpWooClient's rules change, this test fails until fixtures/fake are updated.
*/

beforeEach(function () {
    config([
        'woo.base_url' => 'https://woo.test',
        'woo.key' => 'test-consumer-key',
        'woo.secret' => 'test-consumer-secret',
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
});

afterEach(fn () => Redis::del(RedisTokenBucket::KEY));

/**
 * What a caller can observe about one request: the page, or the facts of the exception.
 *
 * @return array<string, mixed>
 */
function wooOutcome(WooClient $client, WooFixture $fixture): array
{
    try {
        $page = $client->page($fixture->endpoint, $fixture->page, null, $fixture->query);

        return ['page' => [$page->items, $page->page, $page->totalPages, $page->total]];
    } catch (WooRequestException $e) {
        return ['failure' => [$e::class, $e->endpoint, $e->status, $e->attempts, $e->retryable, $e->wooCode]];
    } catch (WooMalformedResponseException $e) {
        return ['failure' => [$e::class, $e->endpoint]];
    } catch (WooException $e) {
        return ['failure' => [$e::class]];
    }
}

it('ends in the same outcome as HttpWooClient for every recorded exchange', function (WooFixture $fixture) {
    Http::fake(fn (Request $request) => Http::response($fixture->body, $fixture->status, $fixture->headers));

    $real = wooOutcome(app(WooClient::class), $fixture);
    $fake = wooOutcome(new FakeWooClient([$fixture]), $fixture);

    expect($fake)->toEqual($real);
})->with(function () {
    foreach (WooFixtures::all() as $fixture) {
        yield $fixture->key() => [$fixture];
    }
});

it('covers every kind of outcome the fixtures are meant to represent', function () {
    $kinds = array_map(function (WooFixture $fixture): string {
        $outcome = wooOutcome(WooFixtures::client(), $fixture);

        if (isset($outcome['page'])) {
            return $outcome['page'][0] === [] ? 'empty' : 'success';
        }

        [$class, , , , $retryable] = $outcome['failure'] + [null, null, null, null, null];

        return $class === WooMalformedResponseException::class ? 'malformed' : ($retryable ? 'retryable' : 'terminal');
    }, WooFixtures::all());
    $kinds = array_values(array_unique($kinds));
    sort($kinds);

    expect($kinds)->toBe(['empty', 'malformed', 'retryable', 'success', 'terminal']);
});

it('leaves the production binding on HttpWooClient', function () {
    expect(app(WooClient::class))->toBeInstanceOf(HttpWooClient::class);
});

it('can be swapped in through the container, and then touches no network, Redis or database', function () {
    $this->app->instance(WooClient::class, WooFixtures::client());
    Redis::del(RedisTokenBucket::KEY);
    Http::fake();
    DB::enableQueryLog();

    $client = app(WooClient::class);
    $pages = iterator_to_array($client->pages('orders'), preserve_keys: false);

    expect($client)->toBeInstanceOf(FakeWooClient::class)
        ->and($pages)->toHaveCount(2)
        ->and($pages[0])->toBeInstanceOf(WooPage::class)
        ->and(DB::getQueryLog())->toBe([])
        ->and(Redis::exists(RedisTokenBucket::KEY))->toBe(0);
    Http::assertNothingSent();
    Sleep::assertNeverSlept();
});
