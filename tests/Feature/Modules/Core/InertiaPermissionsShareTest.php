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
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'view', 'label' => 'View segments']);
    $role = Role::query()->create(['name' => 'analyst', 'label' => 'Analyst']);
    $role->permissions()->attach($permission);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['segments.view']));
});
