<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Services\CatalogSyncService;
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

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'catalog';
    }

    public function handle(CatalogSyncService $catalog): void
    {
        $catalog->syncCategories();
        $catalog->syncProducts();
    }
}
