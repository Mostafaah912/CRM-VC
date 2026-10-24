<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Services\CatalogSyncService;
use App\Modules\Sync\Support\SkipWhenWooIsNotConfigured;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs a full catalog mirror (P6 decision, ARCHITECTURE.md): categories, then products + their
 * variations, through `CatalogSyncService` (P2-05 — already built, already read+wrote real Woo pages via
 * `WooClient`, already idempotent; this job is the entry point it never had). No logic here, only two
 * calls, in the one order that matters (products link to categories that must already exist).
 *
 * Unlike `SyncEntityJob`/`SyncService` (Orders), there is no cursor, window or incremental mode: PRD's
 * catalog SQL is a full mirror every run, and `CatalogSyncService`'s own upserts are already safe to
 * repeat in full. `SyncService::run()` explicitly refuses `SyncEntity::Catalog` so the two paths can
 * never be confused.
 *
 * P6 timing + P7-08 follow-up (docs/architecture/sprint-7.md, "CatalogSyncJob"): MEASURED live, a run takes ~911 s: 310 sequential Woo requests (55 product pages of ~1.5 MB,
 * plus one /variations request per variable product — 251 of them) at ~2.8 s each = 863 s of network wait, only ~49 s of DB/PHP. A 300 s timeout could never hold that:
 * it timed out on 09-30, 10-05 and 10-18, and as a Bus::chain step each timeout stopped the whole nightly chain, so metrics/daily/cohort/segments/reconcile silently
 * never ran. Hence `timeout = 1800` (about twice the measurement; = Horizon's supervisor timeout, 30 s under the queue's retry_after 1830), and `failSoft`: inside the chain a CATCHABLE failure is logged + alerted (CatalogSyncFailed -> SyncFailure alert) and the job ends
 * normally, so the data chain continues; the catalog is a mirror that last night's copy serves fine. Standalone runs (failSoft = false) still throw, as before.
 *
 * `tries = 1`: a partial catalog sync (e.g. Woo returns a page of products but a later page fails) already
 * left every product committed so far in place (each product is its own transaction, `CatalogService`
 * P2-05) — a blind retry would just repeat the same paginated read from page 1; better to see it fail
 * and rerun deliberately once the cause is fixed, same reasoning as every other whole-data job here.
 * `timeout`/`uniqueFor` mirror `ReconcileMonthJob`'s pair, the closest precedent for "one job reads a
 * meaningful chunk of live Woo data synchronously" — may need raising once the real catalog size is known.
 */
final class CatalogSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 2100;

    public function __construct(public readonly bool $failSoft = false)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'catalog';
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [app(SkipWhenWooIsNotConfigured::class)];
    }

    public function handle(CatalogSyncService $catalog): void
    {
        $catalog->syncAll($this->failSoft);
    }
}
