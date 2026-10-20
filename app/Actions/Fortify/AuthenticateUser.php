<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Modules\Core\Services\LoginLockout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Fortify's credential check, with the P8-01 / T9 lockout in front: a locked e-mail is refused
 * before the password is even looked at, so the right password cannot unlock it early.
 */
final class AuthenticateUser
{
    public function __construct(private readonly LoginLockout $lockout) {}

    public function __invoke(Request $request): ?Authenticatable
    {
        $email = (string) $request->input(Fortify::username());

        if ($this->lockout->isLocked($email)) {
            $minutes = (int) ceil($this->lockout->retryAfterSeconds($email) / 60);

            throw ValidationException::withMessages([
                Fortify::username() => "تعداد تلاش‌های ناموفق زیاد بود و حساب موقتاً قفل شد. {$minutes} دقیقه‌ی دیگر دوباره تلاش کنید.",
            ])->status(429);
        }

        $provider = Auth::guard(config('fortify.guard'))->getProvider();
        $user = $provider->retrieveByCredentials([Fortify::username() => $email]);

        if ($user !== null && $provider->validateCredentials($user, ['password' => (string) $request->input('password')])) {
            $this->lockout->clear($email);

            return $user;
        }

        return null;
    }
}
