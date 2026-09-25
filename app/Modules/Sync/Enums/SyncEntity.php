<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/** What a sync run mirrors: the `entity` of sync_jobs and sync_cursors. Refunds ride on the orders run (P2-08); catalog and customers are not run through here yet. */
enum SyncEntity: string
{
    case Orders = 'orders';
}
