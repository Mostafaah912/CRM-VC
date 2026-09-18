<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * Every setting the app knows about: its type, default, and validation.
 * Secrets never live here — Woo/AI credentials belong encrypted in
 * integrations.config (CLAUDE.md §6). Thresholds tuned by the team do.
 */
enum SettingKey: string
{
    case AlertsEnabled = 'alerts.enabled';
    case AlertsDedupeMinutes = 'alerts.dedupe_minutes';
    case AlertsFailedJobsThreshold = 'alerts.failed_jobs_threshold';
    case AlertsConsecutiveSyncFailures = 'alerts.consecutive_sync_failures';
    case AlertsReconciliationDiffPercent = 'alerts.reconciliation_diff_percent';

    public function default(): bool|int|float
    {
        return match ($this) {
            self::AlertsEnabled => true,
            self::AlertsDedupeMinutes => 60,
            self::AlertsFailedJobsThreshold => 20,
            self::AlertsConsecutiveSyncFailures => 2,
            self::AlertsReconciliationDiffPercent => 1.0,
        };
    }

    /** @return list<string> */
    public function rules(): array
    {
        return match ($this) {
            self::AlertsEnabled => ['required', 'boolean'],
            self::AlertsDedupeMinutes => ['required', 'integer', 'min:1', 'max:1440'],
            self::AlertsFailedJobsThreshold, self::AlertsConsecutiveSyncFailures => ['required', 'integer', 'min:1'],
            self::AlertsReconciliationDiffPercent => ['required', 'numeric', 'min:0', 'max:100'],
        };
    }

    public function cast(mixed $value): bool|int|float
    {
        return match ($this) {
            self::AlertsEnabled => (bool) $value,
            self::AlertsDedupeMinutes, self::AlertsFailedJobsThreshold, self::AlertsConsecutiveSyncFailures => (int) $value,
            self::AlertsReconciliationDiffPercent => (float) $value,
        };
    }
}
