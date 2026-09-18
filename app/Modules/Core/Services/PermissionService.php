<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Enums\PermissionEffect;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\PermissionOverride;

/**
 * CLAUDE.md §6/§20 — the mandatory evaluation order:
 *   1. explicit deny override -> DENY (always wins)
 *   2. explicit allow override -> ALLOW
 *   3. role grant             -> ALLOW
 *   4. otherwise              -> DENY (closed by default)
 *
 * `permission_overrides` has a UNIQUE(user_id, permission_id) constraint, so
 * a user can have at most one override per permission — meaning steps 1/2
 * collapse into "an override, if present, always wins outright" below.
 */
final class PermissionService
{
    public function allows(User $user, string $module, string $action): bool
    {
        $permission = Permission::query()
            ->where('module', $module)
            ->where('action', $action)
            ->first();

        if (! $permission) {
            return false;
        }

        $override = PermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->first();

        if ($override) {
            return $override->effect === PermissionEffect::Allow;
        }

        return $user->roles()
            ->whereHas('permissions', fn ($query) => $query->whereKey($permission->id))
            ->exists();
    }

    public function denies(User $user, string $module, string $action): bool
    {
        return ! $this->allows($user, $module, $action);
    }

    /** @return list<string> every "module.action" key the user is currently allowed. */
    public function allowedKeys(User $user): array
    {
        $roleGrantedIds = Permission::query()
            ->whereHas('roles', fn ($query) => $query->whereIn(
                'roles.id',
                $user->roles()->pluck('roles.id'),
            ))
            ->pluck('id');

        $overrides = PermissionOverride::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('permission_id');

        $allowedIds = $roleGrantedIds
            ->reject(fn (int $id) => $overrides->get($id)?->effect === PermissionEffect::Deny)
            ->merge(
                $overrides->filter(fn (PermissionOverride $override) => $override->effect === PermissionEffect::Allow)
                    ->keys(),
            )
            ->unique();

        return array_values(
            Permission::query()
                ->whereIn('id', $allowedIds)
                ->get()
                ->map(fn (Permission $permission) => $permission->key())
                ->all(),
        );
    }
}
