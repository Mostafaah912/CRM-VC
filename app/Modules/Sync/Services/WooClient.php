<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooPage;
use Generator;

/**
 * Read-only boundary to the WooCommerce REST API (Phase 1 never writes to Woo — CLAUDE.md §0).
 * The interface has no write method on purpose; do not add one.
 *
 * `$endpoint` is a path relative to the versioned API root, e.g. 'orders' or 'products/categories'.
 * `$query` carries extra filters; pagination and the frozen window always win over it.
 */
interface WooClient
{
    /**
     * @param  array<string, scalar>  $query
     */
    public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage;

    /**
     * Every page in order, until X-WP-TotalPages is reached or a page is empty.
     *
     * @param  array<string, scalar>  $query
     * @return Generator<int, WooPage>
     */
    public function pages(string $endpoint, ?SyncWindow $window = null, array $query = []): Generator;
}
