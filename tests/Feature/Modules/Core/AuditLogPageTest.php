<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Services\AuditService;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects a guest to login', function () {
    $this->get('/audit')->assertRedirect(route('login'));
});

it('forbids an authenticated user without audit.view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/audit')->assertForbidden();
});

it('renders the audit page for a user whose role grants audit.view', function () {
    $permission = Permission::query()->create(['module' => 'audit', 'action' => 'view', 'label' => 'View audit']);
    $role = Role::query()->create(['name' => 'owner', 'label' => 'Owner']);
    $role->permissions()->attach($permission);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    app(AuditService::class)->record(AuditActorType::System, 'sync.completed', 'X', 1);

    $this->actingAs($user)
        ->get('/audit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/index')
            ->has('logs.data', 1),
        );
});
