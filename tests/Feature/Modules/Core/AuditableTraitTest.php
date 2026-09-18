<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\PermissionOverride;

it('automatically logs creating a permission override without any manual AuditService call', function () {
    $user = User::factory()->create();
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'delete', 'label' => 'x']);

    $this->actingAs($user);

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'effect' => 'deny',
    ]);

    $log = AuditLog::query()->where('action', 'permissionoverride.created')->firstOrFail();

    expect($log->actor_type)->toBe(AuditActorType::User)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->after['effect'])->toBe('deny');
});

it('logs deleting a permission override as a sensitive event, restoring access is auditable too', function () {
    $user = User::factory()->create();
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'delete', 'label' => 'x']);
    $override = PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'effect' => 'deny',
    ]);

    $override->delete();

    $log = AuditLog::query()->where('action', 'permissionoverride.deleted')->firstOrFail();

    expect($log->before['effect'])->toBe('deny');
});

it('attributes the audit entry to the system actor when there is no authenticated user', function () {
    $user = User::factory()->create();
    $permission = Permission::query()->create(['module' => 'segments', 'action' => 'delete', 'label' => 'x']);

    PermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'effect' => 'allow',
    ]);

    $log = AuditLog::query()->where('action', 'permissionoverride.created')->firstOrFail();

    expect($log->actor_type)->toBe(AuditActorType::System)
        ->and($log->user_id)->toBeNull();
});
