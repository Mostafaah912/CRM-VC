<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Enums\RoleName;

/** P8-01 / T1: Owner and Manager must have a CONFIRMED second factor before using the app. */
final class TwoFactorPolicy
{
    public function isEnforced(): bool
    {
        return (bool) config('security.two_factor.enforce');
    }

    public function requiresTwoFactor(User $user): bool
    {
        $roles = array_map(
            fn (string $name): RoleName => RoleName::from($name),
            (array) config('security.two_factor.roles'),
        );

        return $this->isEnforced() && $user->hasRole(...$roles);
    }

    /** True when the user must enrol/confirm 2FA before going any further. */
    public function mustEnrol(User $user): bool
    {
        return $this->requiresTwoFactor($user) && $user->two_factor_confirmed_at === null;
    }
}
