<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/** Where a catalog run is: categories are read first (products link to them), then products page by page. */
enum CatalogSyncPhase: string
{
    case Categories = 'categories';
    case Products = 'products';
}
