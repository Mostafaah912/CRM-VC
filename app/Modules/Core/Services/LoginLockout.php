<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * P8-01 / T9: lock an e-mail after N failed sign-ins (default 10) for a window (default 15 min from the
 * first failure). Counted per e-mail, not per IP, so a distributed guess still hits the lock.
 * Cache-only (RateLimiter): no table, no stored aggregate.
 */
final class LoginLockout
{
    public function isLocked(string $email): bool
    {
        return RateLimiter::tooManyAttempts($this->key($email), $this->maxAttempts());
    }

    public function recordFailure(string $email): void
    {
        RateLimiter::hit($this->key($email), $this->lockMinutes() * 60);
    }

    public function clear(string $email): void
    {
        RateLimiter::clear($this->key($email));
    }

    public function retryAfterSeconds(string $email): int
    {
        return RateLimiter::availableIn($this->key($email));
    }

    private function key(string $email): string
    {
        return 'login-lockout:'.Str::lower(trim($email));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('security.login_lockout.max_attempts'));
    }

    private function lockMinutes(): int
    {
        return max(1, (int) config('security.login_lockout.lock_minutes'));
    }
}
