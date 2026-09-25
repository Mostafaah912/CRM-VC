<?php

declare(strict_types=1);

use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\AuditService;

/*
| P2-07 added one thin entry point to Core's AuditService: recordSystem() for work no user did (sync). Other
| modules may reach Core only through its Services, so they cannot import AuditActorType to call record().
| record() itself is unchanged and covered by AuditServiceTest.
*/

it('records a system action with no user, the given source, and redacted payloads', function () {
    $log = app(AuditService::class)->recordSystem(
        action: 'refund.deleted',
        auditableType: 'App\\Modules\\Orders\\Models\\Refund',
        auditableId: 42,
        before: ['amount' => 100000, 'api_key' => 'must-not-be-stored'],
        after: null,
        source: 'sync',
    );

    $stored = AuditLog::findOrFail($log->id);
    expect($stored->actor_type)->toBe(AuditActorType::System)
        ->and($stored->user_id)->toBeNull()
        ->and([$stored->action, $stored->auditable_id, $stored->source])->toBe(['refund.deleted', 42, 'sync'])
        ->and($stored->before)->toBe(['amount' => 100000, 'api_key' => '[REDACTED]'])
        ->and($stored->after)->toBeNull();
});
