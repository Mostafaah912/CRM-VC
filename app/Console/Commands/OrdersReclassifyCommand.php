<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Orders\Services\OrderRealizedBackfillService;
use Illuminate\Console\Command;

/**
 * `hm:orders-reclassify` (P7-14): after woo.realized_statuses changes, bring the STORED orders.is_realized of existing orders in line with it.
 * Local only, no Woo call, idempotent. Holds no logic: OrderRealizedBackfillService decides. Run a full metrics recompute afterwards.
 */
final class OrdersReclassifyCommand extends Command
{
    protected $signature = 'hm:orders-reclassify';

    protected $description = 'Re-derive orders.is_realized from config woo.realized_statuses for already-stored orders (local only, idempotent)';

    public function handle(OrderRealizedBackfillService $service): int
    {
        $result = $service->reclassify();

        $this->line("made realized: {$result->madeRealized}, made not realized: {$result->madeNotRealized}");

        return self::SUCCESS;
    }
}
