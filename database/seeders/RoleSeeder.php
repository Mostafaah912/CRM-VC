<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * PRD §20's five fixed roles, wired to the permission catalog per the role
 * matrix in that section:
 *   Owner:   everything
 *   Manager: everything except audit, settings, users
 *   Analyst: every `view` action, plus segments.create/edit and ai.request;
 *            never view_full_phone, never a `delete` action
 *   Support: customers.view, orders.view, customers.note only
 *   Viewer:  dashboard.view, metrics.view, analytics.view, ai.view only
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()->get();

        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName->value],
                ['label' => $roleName->label(), 'is_system' => true],
            );

            $role->permissions()->sync(
                $this->permissionsFor($roleName, $permissions)->pluck('id'),
            );
        }
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @return Collection<int, Permission>
     */
    private function permissionsFor(RoleName $role, Collection $permissions): Collection
    {
        return match ($role) {
            RoleName::Owner => $permissions,

            RoleName::Manager => $permissions->reject(
                fn (Permission $permission) => in_array($permission->module, ['audit', 'settings', 'users'], true),
            ),

            RoleName::Analyst => $permissions->filter(
                fn (Permission $permission) => $permission->action === 'view'
                    || $permission->key() === 'segments.create'
                    || $permission->key() === 'segments.edit'
                    || $permission->key() === 'ai.request',
            ),

            RoleName::Support => $permissions->filter(
                fn (Permission $permission) => in_array(
                    $permission->key(),
                    ['customers.view', 'orders.view', 'customers.note'],
                    true,
                ),
            ),

            RoleName::Viewer => $permissions->filter(
                fn (Permission $permission) => in_array(
                    $permission->key(),
                    ['dashboard.view', 'metrics.view', 'analytics.view', 'ai.view'],
                    true,
                ),
            ),
        };
    }
}
