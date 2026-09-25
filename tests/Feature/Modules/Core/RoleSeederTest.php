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

// P2-12: the system pages sit behind `system.view` (health, sync logs) and the existing `identity.review` (identity conflicts).
it('adds system.view to the catalog and grants it to Owner, Manager and Analyst only', function () {
    $granted = fn (string $role): array => Role::query()->where('name', $role)->firstOrFail()->permissions()->get()->map->key()->all();

    expect(collect(PermissionSeeder::catalog())->pluck('module')->all())->toContain('system')
        ->and($granted('owner'))->toContain('system.view')
        ->and($granted('manager'))->toContain('system.view')
        ->and($granted('analyst'))->toContain('system.view')
        ->and($granted('support'))->not->toContain('system.view')
        ->and($granted('viewer'))->not->toContain('system.view');
});

it('grants identity.review to Owner and Manager only', function () {
    $granted = fn (string $role): array => Role::query()->where('name', $role)->firstOrFail()->permissions()->get()->map->key()->all();

    expect($granted('owner'))->toContain('identity.review')
        ->and($granted('manager'))->toContain('identity.review')
        ->and($granted('analyst'))->not->toContain('identity.review')
        ->and($granted('support'))->not->toContain('identity.review')
        ->and($granted('viewer'))->not->toContain('identity.review');
});
