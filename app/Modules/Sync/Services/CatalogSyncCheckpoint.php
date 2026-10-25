<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\CatalogSyncMode;
use App\Modules\Sync\Enums\CatalogSyncPhase;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncStatus;
use App\Modules\Sync\Events\CatalogSyncFailed;
use App\Modules\Sync\Models\SyncCursor;
use App\Modules\Sync\Support\CatalogSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The catalog's cursor and resume point (P8-03g), stored in the `catalog` row of sync_cursors — the same rule as every cursor here:
 * `cursor_value` moves ONLY when a run completes. While a run is in flight (or was killed) `checkpoint` remembers its mode, frozen window,
 * phase and the next products page, so a timeout or a Woo outage costs at most one page, not the whole run.
 *
 * Which run starts:
 *  - a checkpoint younger than RESUME_WITHIN_HOURS resumes (unless a FULL run was asked for and the saved one is incremental);
 *  - otherwise a FULL run when asked for, when no run ever completed, or when the last completed run is older than FULL_AFTER_DAYS
 *    (an incremental window that old is not trusted: changes made while the job was failing would be missed);
 *  - otherwise an INCREMENTAL run reading products modified after `cursor_value - woo.overlap_minutes`.
 */
final class CatalogSyncCheckpoint
{
    public const RESUME_WITHIN_HOURS = 24;

    public const FULL_AFTER_DAYS = 14;

    public function begin(bool $forceFull = false): CatalogSyncRun
    {
        $row = SyncCursor::query()->find(SyncEntity::Catalog->value);
        $now = CarbonImmutable::now('UTC');

        $saved = $this->parse($row->checkpoint ?? null);

        if ($saved !== null && $saved->startedAt->greaterThan($now->subHours(self::RESUME_WITHIN_HOURS)) && ! ($forceFull && $saved->mode === CatalogSyncMode::Incremental)) {
            $run = new CatalogSyncRun($saved->mode, $saved->cursorTo, $saved->modifiedAfter, $saved->phase, $saved->nextPage, $saved->startedAt, resumed: true);
        } else {
            $last = $row?->cursor_value;
            $modifiedAfter = null;

            if (! $forceFull && $last !== null && ! $last->lessThan($now->subDays(self::FULL_AFTER_DAYS))) {
                $modifiedAfter = $last->subMinutes((int) config('woo.overlap_minutes'));
            }

            $run = new CatalogSyncRun(
                $modifiedAfter === null ? CatalogSyncMode::Full : CatalogSyncMode::Incremental,
                $now,
                $modifiedAfter,
                CatalogSyncPhase::Categories,
                1,
                $now,
            );
        }

        $this->save($run, SyncStatus::Running, touchRun: true);

        return $run;
    }

    /** A page (or the categories) is fully written: remember where to continue. */
    public function advance(CatalogSyncRun $run): void
    {
        $this->save($run, SyncStatus::Running);
    }

    /** The run finished: the frozen `cursorTo` becomes the cursor and the checkpoint is cleared. */
    public function complete(CatalogSyncRun $run): void
    {
        SyncCursor::query()->updateOrCreate(['entity' => SyncEntity::Catalog->value], [
            'cursor_value' => $run->cursorTo,
            'last_run_at' => CarbonImmutable::now('UTC'),
            'last_status' => SyncStatus::Completed,
            'checkpoint' => null,
        ]);
    }

    /**
     * Called by the job's failed() hook — for a thrown error AND for a worker timeout kill (where nothing in handle() could catch anything). Marks
     * the cursor failed, keeps the checkpoint and raises ONE CatalogSyncFailed. It needs no Woo client, so it works even when Woo is the problem.
     * Class name only in the log and the event: a Woo/DB message can carry URLs or data.
     */
    public function recordFailure(Throwable $e): void
    {
        $this->fail();
        Log::error('Catalog sync failed; the last mirror is kept and the next run resumes from its checkpoint', ['exception' => $e::class]);
        event(new CatalogSyncFailed($e::class));
    }

    /** A run failed or was killed: keep the checkpoint (so the next run resumes) and say so on the cursor row. */
    public function fail(): void
    {
        $row = SyncCursor::query()->find(SyncEntity::Catalog->value);

        if ($row === null) {
            return;
        }

        $row->forceFill(['last_status' => SyncStatus::Failed, 'last_run_at' => CarbonImmutable::now('UTC')])->save();
    }

    private function save(CatalogSyncRun $run, SyncStatus $status, bool $touchRun = false): void
    {
        $attributes = [
            'last_status' => $status,
            'checkpoint' => [
                'mode' => $run->mode->value,
                'cursor_to' => $run->cursorTo->format('Y-m-d\TH:i:s\Z'),
                'modified_after' => $run->modifiedAfter?->format('Y-m-d\TH:i:s\Z'),
                'phase' => $run->phase->value,
                'next_page' => $run->nextPage,
                'started_at' => $run->startedAt->format('Y-m-d\TH:i:s\Z'),
            ],
        ];

        if ($touchRun) {
            $attributes['last_run_at'] = CarbonImmutable::now('UTC');
        }

        SyncCursor::query()->updateOrCreate(['entity' => SyncEntity::Catalog->value], $attributes);
    }

    /** @param  array<string, mixed>|null  $checkpoint */
    private function parse(?array $checkpoint): ?CatalogSyncRun
    {
        if ($checkpoint === null) {
            return null;
        }

        try {
            $mode = CatalogSyncMode::tryFrom((string) ($checkpoint['mode'] ?? ''));
            $phase = CatalogSyncPhase::tryFrom((string) ($checkpoint['phase'] ?? ''));
            $nextPage = (int) ($checkpoint['next_page'] ?? 0);

            if ($mode === null || $phase === null || $nextPage < 1 || empty($checkpoint['cursor_to']) || empty($checkpoint['started_at'])) {
                return null;
            }

            return new CatalogSyncRun(
                $mode,
                CarbonImmutable::parse((string) $checkpoint['cursor_to'], 'UTC'),
                empty($checkpoint['modified_after']) ? null : CarbonImmutable::parse((string) $checkpoint['modified_after'], 'UTC'),
                $phase,
                $nextPage,
                CarbonImmutable::parse((string) $checkpoint['started_at'], 'UTC'),
            );
        } catch (Throwable) {
            return null;   // an unreadable checkpoint is just "no checkpoint": the run starts over
        }
    }
}
