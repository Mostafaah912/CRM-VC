<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

it('shares an empty permissions list for a guest', function () {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', []));
});

it('shares the resolved module.action keys for an authenticated user', function () {
    // P6-06: /dashboard is now behind dashboard.view (it used to be the starter-kit placeholder, open
    // to any authenticated user) — the role needs it too, alongside the segments.view key this test
    // actually asserts on, or the page itself 403s before any Inertia props are shared.
    $role = Role::query()->create(['name' => 'analyst', 'label' => 'Analyst']);
    foreach ([['segments', 'view'], ['dashboard', 'view']] as [$module, $action]) {
        $permission = Permission::query()->create(['module' => $module, 'action' => $action, 'label' => "{$module}.{$action}"]);
        $role->permissions()->attach($permission);
    }

    $user = User::factory()->create();
    $user->roles()->attach($role);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['segments.view', 'dashboard.view']));
});
