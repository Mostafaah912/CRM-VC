<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Core\Services\Backup\BackupStatusService;
use App\Modules\Sync\Services\SyncHealthService;

/** Props of GET /system/health: the sync/queue/reconciliation snapshot plus (P8-02) backup status. Outside app/Modules for the same reason as HealthCheckService (it reads Sync + Core). */
final class SystemHealthPage
{
    public function __construct(
        private readonly SyncHealthService $health,
        private readonly BackupStatusService $backups,
    ) {}

    /** @return array<string, mixed> */
    public function props(): array
    {
        return [...$this->health->snapshot()->toArray(), 'backup' => $this->backups->toArray()];
    }
}
