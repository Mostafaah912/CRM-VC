<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Enums\AlertKind;
use App\Modules\Core\Enums\AuditActorType;
use App\Modules\Core\Enums\SettingKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Contract for PRD §22 critical alerts. Phase 1 has no notification channel
 * (no SMS/Telegram/email marketing — CLAUDE.md §0): an alert is a critical
 * log line plus an audit entry the team sees on the audit page. Schedulers
 * that raise these arrive in later sprints.
 */
final class AlertService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return bool true if raised, false if disabled or a duplicate inside the dedupe window
     */
    public function critical(AlertKind $kind, string $message, array $context = []): bool
    {
        if (! $this->settings->get(SettingKey::AlertsEnabled)) {
            return false;
        }

        $minutes = (int) $this->settings->get(SettingKey::AlertsDedupeMinutes);

        if (! Cache::add('alert:'.$kind->value, true, now()->addMinutes($minutes))) {
            return false;
        }

        $this->audit->record(
            actorType: AuditActorType::System,
            action: 'alert.critical',
            auditableType: 'alert',
            auditableId: 0,
            after: ['kind' => $kind->value, 'message' => $message, 'context' => $context],
            source: 'alerts',
        );

        Log::critical("[{$kind->value}] {$message}", ['context' => $context]);

        return true;
    }
}
