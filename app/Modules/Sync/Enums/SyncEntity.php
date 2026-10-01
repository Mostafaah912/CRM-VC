<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/**
 * What a sync run mirrors: the `entity` of sync_jobs and sync_cursors. Refunds ride on the orders run
 * (P2-08); customers are not run through here yet.
 *
 * `Catalog` (P6 decision, ARCHITECTURE.md) covers categories+products+variations together, matching how
 * `CatalogSyncService` (P2-05) already treats them as one unit — it is a full mirror every run, with no
 * cursor/window/incremental concept at all, unlike `Orders`. It is validated here (so `hm:sync
 * --entity=catalog` is recognized) but deliberately never reaches `SyncService::run()`, whose window/cursor
 * machinery is Orders-shaped only; `SyncCommand` routes it straight to `CatalogSyncJob` instead, and
 * `SyncService::run()` explicitly refuses anything but `Orders` so the two can never be confused.
 */
enum SyncEntity: string
{
    case Orders = 'orders';
    case Catalog = 'catalog';
}
