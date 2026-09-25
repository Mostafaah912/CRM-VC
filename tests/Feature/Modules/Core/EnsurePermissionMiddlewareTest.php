<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'permission:segments,delete'])
        ->get('/__test/segments/delete', fn () => 'ok');

    // P5-06: the middleware also accepts more than one action for the same module (OR semantics) —
    // e.g. `permission:segments,create,edit` for the segment preview route, so a segments.edit-only
    // holder can use it too without also needing segments.create.
    Route::middleware(['web', 'auth', 'permission:segments,create,edit'])
        ->get('/__test/segments/create-or-edit', fn () => 'ok');
});

it('blocks a guest by redirecting to login', function () {
    $this->get('/__test/segments/delete')->assertRedirect('/login');
});

it('blocks an authenticated user without the permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/__test/segments/delete')
        ->assertForbidden();
});

it('allows a user who holds only the second of two OR-ed actions', function () {
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'edit', 'label' => 'Edit segments']);
    $role = Role::query()->create(['name' => 'analyst', 'label' => 'Analyst']);
    $role->permissions()->attach($permission);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    $this->actingAs($user)
        ->get('/__test/segments/create-or-edit')
        ->assertOk()
        ->assertSee('ok');
});

it('blocks a user who holds neither of two OR-ed actions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/__test/segments/create-or-edit')
        ->assertForbidden();
});

it('blocks a user with a deny override on one OR-ed action but no grant on the other', function () {
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'create', 'label' => 'Create segments']);
    $role = Role::query()->create(['name' => 'manager2', 'label' => 'Manager2']);
    $role->permissions()->attach($permission);

    $user = User::factory()->create();
    $user->roles()->attach($role);
    $user->permissionOverrides()->create([
        'permission_id' => $permission->id,
        'effect' => 'deny',
    ]);

    $this->actingAs($user)
        ->get('/__test/segments/create-or-edit')
        ->assertForbidden();
});

it('allows an authenticated user whose role grants the permission', function () {
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'delete', 'label' => 'Delete segments']);
    $role = Role::query()->create(['name' => 'manager', 'label' => 'Manager']);
    $role->permissions()->attach($permission);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    $this->actingAs($user)
        ->get('/__test/segments/delete')
        ->assertOk()
        ->assertSee('ok');
});

it('blocks a user whose role grants the permission but has an explicit deny override', function () {
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'delete', 'label' => 'Delete segments']);
    $role = Role::query()->create(['name' => 'manager', 'label' => 'Manager']);
    $role->permissions()->attach($permission);

    $user = User::factory()->create();
    $user->roles()->attach($role);
    $user->permissionOverrides()->create([
        'permission_id' => $permission->id,
        'effect' => 'deny',
    ]);

    $this->actingAs($user)
        ->get('/__test/segments/delete')
        ->assertForbidden();
});
