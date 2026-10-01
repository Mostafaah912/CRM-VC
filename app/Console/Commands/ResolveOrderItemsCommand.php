<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Orders\Services\OrderItemBackfillService;
use Illuminate\Console\Command;

/**
 * `hm:resolve-order-items` (P6 decision, ARCHITECTURE.md): re-run the catalog's SKU-only resolution
 * steps against order_items already stored with NULL product/variation ids, entirely from local data —
 * no Woo call, so it can be run any time after a catalog sync, as often as needed (idempotent).
 */
final class ResolveOrderItemsCommand extends Command
{
    protected $signature = 'hm:resolve-order-items';

    protected $description = 'Re-resolve order_items missing a catalog match against the local catalog only (no Woo calls)';

    public function handle(OrderItemBackfillService $service): int
    {
        $result = $service->resolveUnresolved();

        $this->line("resolved as variation: {$result->resolvedAsVariation}, resolved as product: {$result->resolvedAsProduct}, still unresolved: {$result->stillUnresolved}");

        return self::SUCCESS;
    }
}
