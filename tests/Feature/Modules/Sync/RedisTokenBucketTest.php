<?php

declare(strict_types=1);

use App\Modules\Sync\Services\RedisTokenBucket;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;

/*
| PRD §10: "Rate limit: Redis token bucket, 90 req/min". These tests talk to the
| real Redis (test DB/prefix from phpunit.xml) — the limiter is never a PHP counter.
| Time is frozen with travelTo(); Sleep is faked so waits are asserted, not slept.
*/

beforeEach(function () {
    Redis::del(RedisTokenBucket::KEY);
    Sleep::fake();
    $this->freezeTime();
});

afterEach(fn () => Redis::del(RedisTokenBucket::KEY));

it('lets exactly the configured 90 requests through immediately', function () {
    $bucket = new RedisTokenBucket(perMinute: 90);

    foreach (range(1, 90) as $ignored) {
        $bucket->acquire();
    }

    Sleep::assertNeverSlept();
});

it('makes the 91st request wait for one token (1/1.5 s = 667 ms) rather than bypass the limit', function () {
    $bucket = new RedisTokenBucket(perMinute: 90);

    foreach (range(1, 90) as $ignored) {
        $bucket->acquire();
    }
    $bucket->acquire();

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(667)->milliseconds()]);
});

it('queues further requests behind each other instead of letting them share a token', function () {
    $bucket = new RedisTokenBucket(perMinute: 90);

    foreach (range(1, 90) as $ignored) {
        $bucket->acquire();
    }
    $bucket->acquire();
    $bucket->acquire();

    Sleep::assertSequence([Sleep::for(667)->milliseconds(), Sleep::for(1334)->milliseconds()]);
});

it('refills at perMinute/60 tokens per second', function () {
    $bucket = new RedisTokenBucket(perMinute: 90);
    foreach (range(1, 90) as $ignored) {
        $bucket->acquire();
    }

    $this->travel(2)->seconds(); // 3 tokens

    foreach (range(1, 3) as $ignored) {
        $bucket->acquire();
    }
    Sleep::assertNeverSlept();

    $bucket->acquire();
    Sleep::assertSleptTimes(1);
});

it('never refills beyond the capacity', function () {
    $bucket = new RedisTokenBucket(perMinute: 90);
    $bucket->acquire();

    $this->travel(10)->minutes();

    foreach (range(1, 90) as $ignored) {
        $bucket->acquire();
    }
    Sleep::assertNeverSlept();

    $bucket->acquire();
    Sleep::assertSleptTimes(1);
});

it('honours a different configured rate (30/min => 2 s per token)', function () {
    $bucket = new RedisTokenBucket(perMinute: 30);

    foreach (range(1, 30) as $ignored) {
        $bucket->acquire();
    }
    $bucket->acquire();

    Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
});

it('keeps its state in Redis, so separate instances (separate workers) share one budget', function () {
    $workerA = new RedisTokenBucket(perMinute: 90);
    $workerB = new RedisTokenBucket(perMinute: 90);

    foreach (range(1, 60) as $ignored) {
        $workerA->acquire();
    }
    foreach (range(1, 30) as $ignored) {
        $workerB->acquire();
    }
    Sleep::assertNeverSlept();

    $workerA->acquire();
    Sleep::assertSleptTimes(1);
});

it('expires its Redis key so an idle bucket leaves nothing behind', function () {
    (new RedisTokenBucket(perMinute: 90))->acquire();

    $ttl = Redis::pttl(RedisTokenBucket::KEY);

    expect($ttl)->toBeGreaterThan(0)->and($ttl)->toBeLessThanOrEqual(5000);
});

it('rejects a non-positive rate', function (int $perMinute) {
    new RedisTokenBucket(perMinute: $perMinute);
})->with([0, -5])->throws(InvalidArgumentException::class);

it('fails closed when Redis is unreachable — it never lets the request through unlimited', function () {
    config(['database.redis.dead' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]]);
    app()->forgetInstance('redis'); // the manager caches its connection config
    Redis::clearResolvedInstances();

    (new RedisTokenBucket(perMinute: 90, connection: 'dead'))->acquire();
})->throws(RedisException::class);
