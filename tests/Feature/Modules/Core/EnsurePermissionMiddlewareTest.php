<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'permission:segments,delete'])
        ->get('/__test/segments/delete', fn () => 'ok');
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
