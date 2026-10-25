<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/** How a catalog run reads Woo: only what changed since the last completed run, or everything (the weekly rebuild, a first run, a stale cursor). */
enum CatalogSyncMode: string
{
    case Incremental = 'incremental';
    case Full = 'full';
}
