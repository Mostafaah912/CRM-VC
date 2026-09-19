<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Support\SyncHealthReport;
use App\Modules\Sync\Support\SyncRunRow;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * P2-12, read-only: everything the health page shows, gathered in one place. The last good run and the stored reports come from
 * the database; the queue depth and the failed-jobs count come through the queue API (the queue is Redis, so the `jobs` table
 * is empty) and are null when the backend cannot be reached — the page must survive exactly that. Only counts are read from the
 * failed-jobs store, never a payload or a trace, and a queue error is logged by class alone.
 */
final class SyncHealthService
{
    private const QUEUE = 'sync';

    private const RECENT_MONTHS = 3;

    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly FailedJobProviderInterface $failedJobs,
    ) {}

    public function snapshot(): SyncHealthReport
    {
        return new SyncHealthReport(
            $this->lastCompletedRun(),
            $this->syncQueueDepth(),
            $this->failedJobCount(),
            $this->reconciliation->latest(self::RECENT_MONTHS),
            $this->reconciliation->gateOne(),
        );
    }

    private function lastCompletedRun(): ?SyncRunRow
    {
        $run = SyncJob::query()
            ->select(SyncRunRow::COLUMNS)
            ->where('entity', SyncEntity::Orders)
            ->where('status', SyncStatus::Completed)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        return $run === null ? null : SyncRunRow::fromModel($run);
    }

    private function syncQueueDepth(): ?int
    {
        try {
            return Queue::size(self::QUEUE);
        } catch (Throwable $e) {
            Log::warning('Health: the sync queue depth could not be read.', ['error_class' => $e::class]);

            return null;
        }
    }

    private function failedJobCount(): ?int
    {
        try {
            return $this->failedJobs instanceof CountableFailedJobProvider ? $this->failedJobs->count() : null;
        } catch (Throwable $e) {
            Log::warning('Health: the failed-jobs count could not be read.', ['error_class' => $e::class]);

            return null;
        }
    }
}
