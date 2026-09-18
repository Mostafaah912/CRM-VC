<?php

declare(strict_types=1);

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('creates exactly the five fixed roles', function () {
    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(['analyst', 'manager', 'owner', 'support', 'viewer']);
});

it('grants Owner every permission in the catalog', function () {
    $owner = Role::query()->where('name', 'owner')->firstOrFail();

    expect($owner->permissions()->count())->toBe(Permission::query()->count())
        ->and(Permission::query()->count())->toBeGreaterThan(0);
});

it('grants Manager everything except audit, settings, and users', function () {
    $manager = Role::query()->where('name', 'manager')->firstOrFail();
    $keys = $manager->permissions()->get()->map->key()->all();

    expect($keys)->not->toContain('audit.view')
        ->and($keys)->not->toContain('settings.manage')
        ->and($keys)->not->toContain('users.manage')
        ->and($keys)->toContain('customers.view')
        ->and($keys)->toContain('segments.delete');
});

it('grants Analyst every view action plus segments create/edit and ai.request, never view_full_phone or a delete action', function () {
    $analyst = Role::query()->where('name', 'analyst')->firstOrFail();
    $keys = $analyst->permissions()->get()->map->key()->all();

    expect($keys)->toContain('customers.view')
        ->and($keys)->toContain('segments.view')
        ->and($keys)->toContain('segments.create')
        ->and($keys)->toContain('segments.edit')
        ->and($keys)->toContain('ai.request')
        ->and($keys)->not->toContain('customers.view_full_phone')
        ->and($keys)->not->toContain('segments.delete');
});

it('grants Support only customer/order view and customer notes, never metrics or analytics', function () {
    $support = Role::query()->where('name', 'support')->firstOrFail();
    $keys = $support->permissions()->get()->map->key()->all();

    expect($keys)->toEqualCanonicalizing(['customers.view', 'orders.view', 'customers.note']);
});

it('grants Viewer only dashboard, metrics, analytics, and ai view', function () {
    $viewer = Role::query()->where('name', 'viewer')->firstOrFail();
    $keys = $viewer->permissions()->get()->map->key()->all();

    expect($keys)->toEqualCanonicalizing(['dashboard.view', 'metrics.view', 'analytics.view', 'ai.view']);
});

it('is idempotent — running the seeders twice does not duplicate roles or permissions', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::query()->count())->toBe(5)
        ->and(Permission::query()->count())->toBe(count(PermissionSeeder::catalog()));
});
