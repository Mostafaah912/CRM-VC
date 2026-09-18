<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\AuditService;

it('records an audit entry with actor, action, and target', function () {
    $user = User::factory()->create();

    $log = app(AuditService::class)->record(
        actorType: AuditActorType::User,
        action: 'settings.updated',
        auditableType: 'App\\Modules\\Core\\Models\\Setting',
        auditableId: 1,
        before: ['value' => 10],
        after: ['value' => 15],
        user: $user,
    );

    expect($log->user_id)->toBe($user->id)
        ->and($log->actor_type)->toBe(AuditActorType::User)
        ->and($log->action)->toBe('settings.updated')
        ->and($log->auditable_type)->toBe('App\\Modules\\Core\\Models\\Setting')
        ->and($log->before)->toBe(['value' => 10])
        ->and($log->after)->toBe(['value' => 15]);

    expect(AuditLog::query()->count())->toBe(1);
});

it('allows a system actor with no user', function () {
    $log = app(AuditService::class)->record(
        actorType: AuditActorType::System,
        action: 'sync.completed',
        auditableType: 'App\\Modules\\Sync\\Models\\SyncJob',
        auditableId: 1,
    );

    expect($log->user_id)->toBeNull()
        ->and($log->actor_type)->toBe(AuditActorType::System);
});

it('redacts a password field in the before/after payload', function () {
    $log = app(AuditService::class)->record(
        actorType: AuditActorType::System,
        action: 'user.updated',
        auditableType: User::class,
        auditableId: 1,
        before: ['name' => 'Ali', 'password' => 'super-secret-hash'],
        after: ['name' => 'Ali Reza', 'password' => 'another-hash'],
    );

    expect($log->before['password'])->toBe('[REDACTED]')
        ->and($log->after['password'])->toBe('[REDACTED]')
        ->and($log->before['name'])->toBe('Ali')
        ->and($log->after['name'])->toBe('Ali Reza');
});

it('redacts two-factor secrets, remember tokens, and API/consumer secrets', function () {
    $log = app(AuditService::class)->record(
        actorType: AuditActorType::System,
        action: 'user.updated',
        auditableType: User::class,
        auditableId: 1,
        after: [
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => '["a","b"]',
            'remember_token' => 'abc123',
        ],
    );

    foreach (['two_factor_secret', 'two_factor_recovery_codes', 'remember_token'] as $key) {
        expect($log->after[$key])->toBe('[REDACTED]');
    }
});

it('redacts WooCommerce and AI credential fields', function () {
    $log = app(AuditService::class)->record(
        actorType: AuditActorType::System,
        action: 'integration.updated',
        auditableType: 'App\\Modules\\Sync\\Models\\Integration',
        auditableId: 1,
        after: [
            'key' => 'woo',
            'consumer_key' => 'ck_abc',
            'consumer_secret' => 'cs_abc',
            'config' => 'encrypted-blob',
        ],
    );

    expect($log->after['consumer_key'])->toBe('[REDACTED]')
        ->and($log->after['consumer_secret'])->toBe('[REDACTED]')
        ->and($log->after['config'])->toBe('[REDACTED]')
        ->and($log->after['key'])->toBe('woo');
});

it('never touches audit_logs when the change has no before/after data', function () {
    $log = app(AuditService::class)->record(
        actorType: AuditActorType::System,
        action: 'job.finished',
        auditableType: 'App\\Modules\\Sync\\Models\\SyncJob',
        auditableId: 1,
    );

    expect($log->before)->toBeNull()->and($log->after)->toBeNull();
});

it('paginates the most recent audit entries first', function () {
    app(AuditService::class)->record(AuditActorType::System, 'first', 'X', 1);
    app(AuditService::class)->record(AuditActorType::System, 'second', 'X', 2);

    $page = app(AuditService::class)->paginate(perPage: 10);

    expect($page->items()[0]->action)->toBe('second')
        ->and($page->items()[1]->action)->toBe('first');
});
