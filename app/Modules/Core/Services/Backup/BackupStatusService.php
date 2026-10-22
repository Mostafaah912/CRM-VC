<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

use App\Modules\Core\Services\AuditService;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/** What the system-health page and HealthCheckJob read: when the last good backup and restore test happened. From audit entries — no table. */
final class BackupStatusService
{
    public function __construct(private readonly AuditService $audit) {}

    public function enabled(): bool
    {
        return (bool) config('backup.enabled');
    }

    public function lastBackupAt(): ?CarbonImmutable
    {
        return $this->audit->latestAt('backup.completed');
    }

    public function lastRestoreTestAt(): ?CarbonImmutable
    {
        return $this->audit->latestAt('backup.restore_test.completed');
    }

    /** No successful backup yet, or the newest is older than the RPO guard. Only meaningful when enabled. */
    public function isStale(?CarbonImmutable $now = null): bool
    {
        $last = $this->lastBackupAt();

        return $last === null || $last->lessThan(($now ?? CarbonImmutable::now('UTC'))->subHours((int) config('backup.stale_after_hours')));
    }

    /** Quarterly reminder: measured from the last passed test, or — before any test — from the first successful backup. */
    public function restoreTestOverdue(?CarbonImmutable $now = null): bool
    {
        $reference = $this->lastRestoreTestAt() ?? $this->audit->earliestAt('backup.completed');

        return $reference !== null
            && $reference->lessThan(($now ?? CarbonImmutable::now('UTC'))->subDays((int) config('backup.restore_test.due_every_days')));
    }

    /** @return array{enabled: bool, last_backup_at: string|null, last_restore_test_at: string|null, stale: bool, restore_test_overdue: bool} */
    public function toArray(?CarbonImmutable $now = null): array
    {
        return [
            'enabled' => $this->enabled(),
            'last_backup_at' => TehranDateTime::formatOrNull($this->lastBackupAt()),
            'last_restore_test_at' => TehranDateTime::formatOrNull($this->lastRestoreTestAt()),
            'stale' => $this->enabled() && $this->isStale($now),
            'restore_test_overdue' => $this->enabled() && $this->restoreTestOverdue($now),
        ];
    }
}
