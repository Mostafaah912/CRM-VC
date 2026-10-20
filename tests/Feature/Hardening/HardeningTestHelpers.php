<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use App\Models\User;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\Role;

/** Test data only: a user holding one of the five real role names. */
final class HardeningTestHelpers
{
    public static function userWithRole(RoleName $name): User
    {
        $role = Role::query()->firstOrCreate(['name' => $name->value], ['label' => $name->label(), 'is_system' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }
}
