<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('gives users the CRM columns with the right defaults', function () {
    expect(Schema::hasColumns('users', ['is_active', 'last_login_at']))->toBeTrue();

    $id = DB::table('users')->insertGetId([
        'name' => 'Test Owner',
        'email' => 'owner@example.test',
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = DB::table('users')->find($id);

    expect((bool) $user->is_active)->toBeTrue()
        ->and($user->last_login_at)->toBeNull();
});

it('stores every domain timestamp column as timestamptz', function () {
    $rows = DB::select("
        select table_name, column_name
        from information_schema.columns
        where table_schema = 'public'
          and data_type = 'timestamp without time zone'
          and table_name not in ('jobs', 'job_batches', 'passkeys')
    ");

    expect($rows)->toBeEmpty();
});

it('rejects a permission_overrides row with an invalid effect', function () {
    [$userId, $permissionId] = seedUserAndPermission();

    expect(fn () => DB::table('permission_overrides')->insert([
        'user_id' => $userId,
        'permission_id' => $permissionId,
        'effect' => 'maybe',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('accepts allow and deny as the only permission_overrides effects', function () {
    [$userId, $permissionId] = seedUserAndPermission();

    DB::table('permission_overrides')->insert([
        'user_id' => $userId,
        'permission_id' => $permissionId,
        'effect' => 'deny',
        'created_at' => now(),
    ]);

    expect(DB::table('permission_overrides')->where('user_id', $userId)->value('effect'))->toBe('deny');
});

it('enforces one override per user/permission pair', function () {
    [$userId, $permissionId] = seedUserAndPermission();

    DB::table('permission_overrides')->insert([
        'user_id' => $userId,
        'permission_id' => $permissionId,
        'effect' => 'deny',
        'created_at' => now(),
    ]);

    expect(fn () => DB::table('permission_overrides')->insert([
        'user_id' => $userId,
        'permission_id' => $permissionId,
        'effect' => 'allow',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an audit_logs row with an invalid actor_type', function () {
    expect(fn () => DB::table('audit_logs')->insert([
        'actor_type' => 'robot',
        'action' => 'test.action',
        'auditable_type' => 'App\\Models\\Test',
        'auditable_id' => 1,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('defaults audit_logs.actor_type to user', function () {
    DB::table('audit_logs')->insert([
        'action' => 'test.action',
        'auditable_type' => 'App\\Models\\Test',
        'auditable_id' => 1,
        'created_at' => now(),
    ]);

    expect(DB::table('audit_logs')->value('actor_type'))->toBe('user');
});

it('enforces unique role names', function () {
    DB::table('roles')->insert(['name' => 'owner', 'label' => 'Owner', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('roles')->insert(['name' => 'owner', 'label' => 'Owner Duplicate', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

it('enforces unique module/action pairs on permissions', function () {
    DB::table('permissions')->insert(['module' => 'segments', 'action' => 'delete', 'label' => 'Delete segments']);

    expect(fn () => DB::table('permissions')->insert(['module' => 'segments', 'action' => 'delete', 'label' => 'dup']))
        ->toThrow(QueryException::class);
});

it('cascades role deletion to role_user and permission_role', function () {
    $roleId = DB::table('roles')->insertGetId(['name' => 'temp', 'label' => 'Temp', 'created_at' => now(), 'updated_at' => now()]);
    $userId = DB::table('users')->insertGetId([
        'name' => 'Temp User', 'email' => 'temp@example.test', 'password' => bcrypt('secret'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $permissionId = DB::table('permissions')->insertGetId(['module' => 'audit', 'action' => 'view', 'label' => 'View audit']);

    DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);

    DB::table('roles')->where('id', $roleId)->delete();

    expect(DB::table('role_user')->where('role_id', $roleId)->exists())->toBeFalse()
        ->and(DB::table('permission_role')->where('role_id', $roleId)->exists())->toBeFalse();
});

it('treats settings.key as the primary key and rejects duplicates', function () {
    DB::table('settings')->insert(['key' => 'ai.budget_usd', 'value' => json_encode(15), 'updated_at' => now()]);

    expect(fn () => DB::table('settings')->insert(['key' => 'ai.budget_usd', 'value' => json_encode(20), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

/** @return array{0: int, 1: int} [userId, permissionId] */
function seedUserAndPermission(): array
{
    $userId = DB::table('users')->insertGetId([
        'name' => 'Test User', 'email' => uniqid('user', true).'@example.test', 'password' => bcrypt('secret'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $permissionId = DB::table('permissions')->insertGetId([
        'module' => uniqid('module', true), 'action' => 'delete', 'label' => 'Delete',
    ]);

    return [$userId, $permissionId];
}
