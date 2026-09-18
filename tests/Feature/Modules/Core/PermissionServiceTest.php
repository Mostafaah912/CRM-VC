<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\PermissionEffect;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\PermissionOverride;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Services\PermissionService;

function makePermission(string $module = 'segments', string $action = 'delete'): Permission
{
    return Permission::query()->create([
        'module' => $module,
        'action' => $action,
        'label' => "{$module}.{$action}",
    ]);
}

function makeRoleWithPermission(Permission $permission, string $name = 'manager'): Role
{
    $role = Role::query()->create(['name' => $name, 'label' => $name]);
    $role->permissions()->attach($permission);

    return $role;
}

/**
 * CLAUDE.md §6/§20: explicit deny > explicit allow > role grant > default deny.
 * This is the highest-risk area in the whole app — a wrong precedence here
 * means someone sees data they shouldn't, or is locked out of something
 * they should have. Every branch of the four-step order is covered below.
 */
it('denies by default when the user has no role and no override', function () {
    $permission = makePermission();
    $user = User::factory()->create();

    $service = app(PermissionService::class);

    expect($service->allows($user, 'segments', 'delete'))->toBeFalse();
});

it('allows when a role grants the permission and there is no override', function () {
    $permission = makePermission();
    $role = makeRoleWithPermission($permission);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    $service = app(PermissionService::class);

    expect($service->allows($user, 'segments', 'delete'))->toBeTrue();
});

it('CRITICAL: an explicit deny override beats a role grant', function () {
    $permission = makePermission('segments', 'delete');
    $role = makeRoleWithPermission($permission, 'manager');
    $user = User::factory()->create();
    $user->roles()->attach($role);

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'effect' => PermissionEffect::Deny,
    ]);

    $service = app(PermissionService::class);

    expect($service->allows($user, 'segments', 'delete'))->toBeFalse();
});

it('an explicit allow override beats having no role grant', function () {
    $permission = makePermission();
    $user = User::factory()->create();

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'effect' => PermissionEffect::Allow,
    ]);

    $service = app(PermissionService::class);

    expect($service->allows($user, 'segments', 'delete'))->toBeTrue();
});

it('an explicit deny override beats having no role grant either', function () {
    $permission = makePermission();
    $user = User::factory()->create();

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'effect' => PermissionEffect::Deny,
    ]);

    $service = app(PermissionService::class);

    expect($service->allows($user, 'segments', 'delete'))->toBeFalse();
});

it('an explicit allow override applies even without any role at all', function () {
    $permission = makePermission();
    $user = User::factory()->create();

    expect($user->roles()->count())->toBe(0);

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'effect' => PermissionEffect::Allow,
    ]);

    $service = app(PermissionService::class);

    expect($service->allows($user, 'segments', 'delete'))->toBeTrue();
});

it('denies for a permission that does not exist in the catalog', function () {
    $user = User::factory()->create();

    $service = app(PermissionService::class);

    expect($service->allows($user, 'nonexistent', 'action'))->toBeFalse();
});

it('is scoped per permission: an override on one permission does not affect another', function () {
    $deletePermission = makePermission('segments', 'delete');
    $viewPermission = makePermission('segments', 'view');
    $role = makeRoleWithPermission($viewPermission, 'analyst');
    $role->permissions()->attach($deletePermission);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $deletePermission->id,
        'effect' => PermissionEffect::Deny,
    ]);

    $service = app(PermissionService::class);

    expect($service->allows($user, 'segments', 'delete'))->toBeFalse()
        ->and($service->allows($user, 'segments', 'view'))->toBeTrue();
});

it('is scoped per user: an override on one user does not affect another with the same role', function () {
    $permission = makePermission();
    $role = makeRoleWithPermission($permission);

    $deniedUser = User::factory()->create();
    $allowedUser = User::factory()->create();
    $deniedUser->roles()->attach($role);
    $allowedUser->roles()->attach($role);

    PermissionOverride::query()->create([
        'user_id' => $deniedUser->id,
        'permission_id' => $permission->id,
        'effect' => PermissionEffect::Deny,
    ]);

    $service = app(PermissionService::class);

    expect($service->allows($deniedUser, 'segments', 'delete'))->toBeFalse()
        ->and($service->allows($allowedUser, 'segments', 'delete'))->toBeTrue();
});

it('denies returns the inverse of allows', function () {
    $permission = makePermission();
    $user = User::factory()->create();

    $service = app(PermissionService::class);

    expect($service->denies($user, 'segments', 'delete'))->toBeTrue();

    $role = makeRoleWithPermission($permission);
    $user->roles()->attach($role);

    expect($service->denies($user, 'segments', 'delete'))->toBeFalse();
});

it('allowedKeys returns role-granted permissions as module.action strings', function () {
    $view = makePermission('segments', 'view');
    $create = makePermission('segments', 'create');
    $role = Role::query()->create(['name' => 'analyst', 'label' => 'Analyst']);
    $role->permissions()->attach([$view->id, $create->id]);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    $service = app(PermissionService::class);

    expect($service->allowedKeys($user))
        ->toEqualCanonicalizing(['segments.view', 'segments.create']);
});

it('allowedKeys excludes a role-granted permission the user has an explicit deny override on', function () {
    $view = makePermission('segments', 'view');
    $delete = makePermission('segments', 'delete');
    $role = Role::query()->create(['name' => 'manager', 'label' => 'Manager']);
    $role->permissions()->attach([$view->id, $delete->id]);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $delete->id,
        'effect' => PermissionEffect::Deny,
    ]);

    $service = app(PermissionService::class);

    expect($service->allowedKeys($user))->toEqualCanonicalizing(['segments.view']);
});

it('allowedKeys includes an explicit allow override beyond the user\'s role grants', function () {
    $view = makePermission('segments', 'view');
    $adminOnly = makePermission('settings', 'manage');
    $role = Role::query()->create(['name' => 'viewer', 'label' => 'Viewer']);
    $role->permissions()->attach($view->id);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $adminOnly->id,
        'effect' => PermissionEffect::Allow,
    ]);

    $service = app(PermissionService::class);

    expect($service->allowedKeys($user))
        ->toEqualCanonicalizing(['segments.view', 'settings.manage']);
});
