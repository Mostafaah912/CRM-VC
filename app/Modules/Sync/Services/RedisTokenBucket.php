<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * Redis token bucket for the Woo API (PRD §10: 90 req/min). State lives in Redis so every
 * worker shares one budget; the check-and-take is one Lua script, so it is atomic.
 *
 * Capacity = perMinute, refilled continuously at perMinute/60 per second. An idle bucket
 * therefore allows a burst of up to `perMinute`, then a steady perMinute/min.
 * acquire() takes a token and, when the bucket is empty, RESERVES the next one and sleeps
 * until it is due — callers queue behind each other. It never proceeds without a token,
 * and if Redis is unreachable it throws: the limit is never silently bypassed.
 */
final class RedisTokenBucket
{
    public const KEY = 'hm:woo:rate-limit';

    /** Returns the milliseconds the caller must wait before using its (reserved) token. */
    private const SCRIPT = <<<'LUA'
local capacity = tonumber(ARGV[1])
local per_ms = capacity / 60000
local now = tonumber(ARGV[2])

local state = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
local tokens = tonumber(state[1])
local ts = tonumber(state[2])
if tokens == nil or ts == nil then
    tokens = capacity
    ts = now
end
if now > ts then
    tokens = math.min(capacity, tokens + (now - ts) * per_ms)
    ts = now
end

tokens = tokens - 1
local wait = 0
if tokens < 0 then
    wait = math.ceil(-tokens / per_ms)
end

redis.call('HSET', KEYS[1], 'tokens', tostring(tokens), 'ts', tostring(ts))
redis.call('PEXPIRE', KEYS[1], math.ceil((capacity - tokens) / per_ms) + 1000)

return wait
LUA;

    public function __construct(
        private readonly int $perMinute,
        private readonly ?string $connection = null,
    ) {
        if ($perMinute < 1) {
            throw new InvalidArgumentException('woo.rate_limit_per_minute must be at least 1.');
        }
    }

    public function acquire(): void
    {
        // phpredis argument order (script, [KEYS..., ARGV...], numKeys) — the project standardises on
        // REDIS_CLIENT=phpredis; this is exactly what Laravel's own eval() wrapper sends.
        $waitMs = (int) Redis::connection($this->connection)->command('eval', [
            self::SCRIPT,
            [self::KEY, $this->perMinute, (int) now()->getPreciseTimestamp(3)],
            1,
        ]);

        if ($waitMs > 0) {
            Sleep::for($waitMs)->milliseconds();
        }
    }
}
