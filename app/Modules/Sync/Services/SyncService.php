<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncLogLevel;
use App\Modules\Sync\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Jobs\SyncPageJob;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Models\SyncJob;
use App\Modules\Sync\Models\SyncLog;
use App\Modules\Sync\Support\OrderSyncResult;
use App\Modules\Sync\Support\SafeErrorText;
use App\Modules\Sync\Support\SyncWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Owns a sync RUN (PRD §10, CLAUDE.md §5). run() opens one sync_jobs row whose window is FROZEN at that moment
 * (cursor_to = now(), cursor_from = stored cursor - overlap) and queues page 1. Each SyncPageJob then calls runPage():
 * one page of orders through OrderSyncService, the refunds of the orders that need them through RefundSyncService, a log
 * line, and either the next page job (Woo says more follow) or completion. Every page of a run uses the same stored
 * window, so nothing drifts while it paginates.
 *
 * The stored cursor moves in exactly one place — complete() — and only for a run that is still `running`: a failed or
 * abandoned run never touches it, so the next run re-reads the same window (idempotent upserts make that safe). Run
 * counters and the failure count are RECOMPUTED from rows and SET, never incremented. A run that stops being `running`
 * (abandoned by a newer start) can no longer record a page, complete, or move the cursor: every write re-checks the run
 * under a lock, taken cursor row first, run row second.
 *
 * Any failure ends the run `failed`, leaves the cursor alone, and rethrows. A record that fails deterministically
 * therefore holds the cursor at the same place until it is fixed — by design: better a stalled cursor than a hole in
 * the data. Only orders/refunds are run here; full and webhook modes belong to later features.
 */
final class SyncService
{
    /** A `running` run this old is presumed dead: a newer start abandons it. Younger runs block a new start. */
    public const STALE_AFTER_SECONDS = 3600;

    private const RUN_STARTED = 'Run started';

    private const RUN_ABANDONED = 'Run abandoned';

    private const PAGE_SYNCED = 'Page synced';

    private const RUN_COMPLETED = 'Run completed';

    private const PAGE_SKIPPED = 'Page skipped: the run is no longer running';

    private const ABANDONED_REASON = 'abandoned';

    public function __construct(
        private readonly OrderSyncService $orders,
        private readonly RefundSyncService $refunds,
    ) {}

    /**
     * Start a run and queue its first page. Returns null — and changes nothing — while a run for the entity is younger
     * than STALE_AFTER_SECONDS; an older `running` one is failed as "abandoned" first. The cursor is never touched here.
     */
    public function run(SyncEntity $entity, SyncMode $mode = SyncMode::Incremental): ?SyncJob
    {
        if ($mode !== SyncMode::Incremental) {
            throw new InvalidArgumentException("Sync mode {$mode->value} is not run through SyncService yet: only incremental is.");
        }

        $run = DB::transaction(function () use ($entity, $mode): ?SyncJob {
            $cursor = $this->lockedCursor($entity);
            $now = CarbonImmutable::now('UTC');
            $running = SyncJob::query()->where('entity', $entity)->where('status', SyncStatus::Running)->orderBy('id')->lockForUpdate()->get();

            if ($running->contains(fn (SyncJob $r): bool => $r->started_at->greaterThan($now->subSeconds(self::STALE_AFTER_SECONDS)))) {
                return null;
            }

            foreach ($running as $stale) {
                $this->markFailed($stale, self::ABANDONED_REASON, $now);
                $this->log($stale, SyncLogLevel::Warning, self::RUN_ABANDONED, ['age_seconds' => $now->getTimestamp() - $stale->started_at->getTimestamp()]);
            }

            $window = SyncWindow::freeze($cursor->cursor_value);
            $run = SyncJob::create([
                'entity' => $entity,
                'mode' => $mode,
                'status' => SyncStatus::Running,
                'cursor_from' => $window->modifiedAfter,
                'cursor_to' => $window->modifiedBefore,
                'pages_processed' => 0,
                'records_processed' => 0,
                'records_failed' => 0,
                'started_at' => $now,
            ]);

            $cursor->last_run_at = $now;
            $this->refreshCursorState($cursor, SyncStatus::Running);
            $this->log($run, SyncLogLevel::Info, self::RUN_STARTED, [
                'entity' => $entity->value,
                'mode' => $mode->value,
                'cursor_from' => $window->modifiedAfter?->format('Y-m-d\TH:i:s'),
                'cursor_to' => $window->modifiedBefore->format('Y-m-d\TH:i:s'),
            ]);

            return $run;
        });

        if ($run !== null) {
            SyncPageJob::dispatch($run->id, 1);
        }

        return $run;
    }

    /**
     * Sync one page of a run. A run that is not `running` any more does nothing (a job of an abandoned run may still
     * wake up). Any failure marks the run failed and is rethrown so the queue records it too.
     */
    public function runPage(int $syncJobId, int $page): void
    {
        $run = SyncJob::query()->findOrFail($syncJobId);

        if ($run->status !== SyncStatus::Running) {
            $this->log($run, SyncLogLevel::Warning, self::PAGE_SKIPPED, ['page' => $page, 'status' => $run->status->value]);

            return;
        }

        try {
            $result = $this->orders->syncPage($page, new SyncWindow($run->cursor_from, $run->cursor_to));
            $refundsSynced = array_sum(array_map(
                fn (int $wooOrderId): int => $this->refunds->syncOrder($wooOrderId)->refunds,
                $result->refundOrderIds,
            ));
        } catch (Throwable $e) {
            $this->failRun($run->id, $e, $page);

            throw $e;
        }

        if (! $this->recordPage($run->id, $page, $result, $refundsSynced)) {
            return;
        }

        if ($result->hasMore) {
            SyncPageJob::dispatch($run->id, $page + 1);

            return;
        }

        $this->complete($run);
    }

    /**
     * End a still-running run as failed: the reason is logged (scrubbed), the cursor is left as it was, and the failure
     * count is recomputed. Idempotent — a run that already ended, or does not exist, is left alone — so the job's
     * failed() hook can call it after runPage() already did.
     */
    public function failRun(int $syncJobId, Throwable $e, ?int $page = null): void
    {
        $run = SyncJob::query()->find($syncJobId);

        if ($run === null) {
            return;
        }

        DB::transaction(function () use ($run, $e, $page): void {
            $cursor = $this->lockedCursor($run->entity);
            $locked = $this->lockedRun($run->id);

            if ($locked->status !== SyncStatus::Running) {
                return;
            }

            $this->markFailed($locked, SafeErrorText::from($e, 2000), CarbonImmutable::now('UTC'));
            $this->refreshCursorState($cursor, SyncStatus::Failed);
            $this->log(
                $locked,
                SyncLogLevel::Error,
                ($page === null ? 'Run failed: ' : "Run failed on page {$page}: ").SafeErrorText::from($e),
                $page === null ? ['exception' => class_basename($e)] : ['page' => $page, 'exception' => class_basename($e)],
            );
        });
    }

    /** @return bool false when the run stopped being `running` meanwhile — the page is then not recorded and the run goes no further */
    private function recordPage(int $runId, int $page, OrderSyncResult $result, int $refundsSynced): bool
    {
        return DB::transaction(function () use ($runId, $page, $result, $refundsSynced): bool {
            $run = $this->lockedRun($runId);

            if ($run->status !== SyncStatus::Running) {
                return false;
            }

            $this->log($run, SyncLogLevel::Info, self::PAGE_SYNCED, [
                'page' => $page,
                'orders' => $result->orders,
                'items' => $result->items,
                'refund_orders' => count($result->refundOrderIds),
                'refunds' => $refundsSynced,
            ]);
            $this->recomputeCounters($run);

            return true;
        });
    }

    /** The one place the stored cursor advances: a run that is still running has read its whole frozen window. */
    private function complete(SyncJob $run): void
    {
        DB::transaction(function () use ($run): void {
            $cursor = $this->lockedCursor($run->entity);
            $locked = $this->lockedRun($run->id);

            if ($locked->status !== SyncStatus::Running) {
                return;
            }

            $now = CarbonImmutable::now('UTC');
            $locked->status = SyncStatus::Completed;
            $locked->finished_at = $now;
            $locked->save();

            $cursor->cursor_value = $locked->cursor_to;
            $this->refreshCursorState($cursor, SyncStatus::Completed);
            $this->log($locked, SyncLogLevel::Info, self::RUN_COMPLETED, ['pages' => $locked->pages_processed, 'records' => $locked->records_processed]);
        });
    }

    private function markFailed(SyncJob $run, string $error, CarbonImmutable $at): void
    {
        $run->status = SyncStatus::Failed;
        $run->error = $error;
        $run->finished_at = $at;
        $run->save();
    }

    /**
     * pages_processed / records_processed are SET from the run's own page logs (records = orders + refunds), never
     * incremented. One log per page counts once, even if a page were somehow synced twice.
     */
    private function recomputeCounters(SyncJob $run): void
    {
        $byPage = SyncLog::query()->where('sync_job_id', $run->id)->where('message', self::PAGE_SYNCED)->orderBy('id')->get()
            ->keyBy(fn (SyncLog $log): int => (int) ($log->context['page'] ?? 0));

        $run->pages_processed = $byPage->count();
        $run->records_processed = $byPage->sum(fn (SyncLog $log): int => (int) ($log->context['orders'] ?? 0) + (int) ($log->context['refunds'] ?? 0));
        $run->save();
    }

    /**
     * last_status is SET, and consecutive_failures is RECOMPUTED from the run history: the failed runs of the entity
     * since its last completed run. The caller holds the cursor lock. cursor_value is never touched here.
     */
    private function refreshCursorState(SyncCursor $cursor, SyncStatus $status): void
    {
        $lastCompleted = (int) SyncJob::query()->where('entity', $cursor->entity)->where('status', SyncStatus::Completed)->max('id');

        $cursor->last_status = $status;
        $cursor->consecutive_failures = SyncJob::query()->where('entity', $cursor->entity)->where('status', SyncStatus::Failed)->where('id', '>', $lastCompleted)->count();
        $cursor->save();
    }

    /** The entity's cursor row, created empty on first use, and locked: it serialises every start, failure and completion. */
    private function lockedCursor(SyncEntity $entity): SyncCursor
    {
        SyncCursor::query()->insertOrIgnore([['entity' => $entity->value]]);

        return SyncCursor::query()->whereKey($entity->value)->lockForUpdate()->firstOrFail();
    }

    private function lockedRun(int $id): SyncJob
    {
        return SyncJob::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $context  counts, ids and window bounds only — never a payload or a phone
     */
    private function log(SyncJob $run, SyncLogLevel $level, string $message, array $context = []): void
    {
        SyncLog::create([
            'sync_job_id' => $run->id,
            'level' => $level,
            'message' => mb_substr($message, 0, 500),
            'context' => $context === [] ? null : $context,
        ]);
    }
}
