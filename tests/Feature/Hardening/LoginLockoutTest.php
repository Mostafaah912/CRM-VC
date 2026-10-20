<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Services\LoginLockout;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('login-lockout:locked@example.com');
});

function failLogin(object $test, string $email, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        // Fortify's own per-minute throttle is a separate layer; reset it so only the lockout is under test.
        RateLimiter::clear(md5('login'.implode('|', [strtolower($email), '127.0.0.1'])));
        $test->post(route('login.store'), ['email' => $email, 'password' => 'wrong-password']);
    }
}

it('locks an account after 10 failed attempts, even for the right password', function () {
    $user = User::factory()->create(['email' => 'locked@example.com']);

    failLogin($this, $user->email, 10);

    RateLimiter::clear(md5('login'.implode('|', [$user->email, '127.0.0.1'])));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('does not lock before the 10th failure', function () {
    $user = User::factory()->create(['email' => 'locked@example.com']);

    failLogin($this, $user->email, 9);

    RateLimiter::clear(md5('login'.implode('|', [$user->email, '127.0.0.1'])));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('resets the failure count after a successful sign-in', function () {
    $user = User::factory()->create(['email' => 'locked@example.com']);

    failLogin($this, $user->email, 5);
    RateLimiter::clear(md5('login'.implode('|', [$user->email, '127.0.0.1'])));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    failLogin($this, $user->email, 9);
    RateLimiter::clear(md5('login'.implode('|', [$user->email, '127.0.0.1'])));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('keeps the per-minute login throttle (429 after 5 tries)', function () {
    $user = User::factory()->create(['email' => 'locked@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('counts the lockout per e-mail case-insensitively', function () {
    User::factory()->create(['email' => 'locked@example.com']);

    failLogin($this, 'Locked@Example.com', 10);

    expect(app(LoginLockout::class)->isLocked('locked@example.com'))->toBeTrue();
});
