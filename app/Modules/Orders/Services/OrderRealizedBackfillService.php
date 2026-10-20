<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Events\OrderSynced;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\RealizedReclassifyResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * `orders.is_realized` is STORED at write time from config('woo.realized_statuses') (OrderService::upsert via OrderStatusMapper). When that list
 * changes (P7-14: `taroff-4` joined it) every order already stored under the old list keeps the old flag until it is touched again. This brings
 * them in line, entirely locally (no Woo call), chunked by id, idempotent (it only ever selects rows whose flag disagrees with the status, so a
 * repeat run finds nothing), in BOTH directions (a status removed from the list is un-realized too). Each changed order dispatches the ordinary
 * OrderSynced event, so the Metrics listener marks its customer dirty — the status is never named here, only asked of OrderStatusMapper.
 */
final class OrderRealizedBackfillService
{
    private const CHUNK = 500;

    public function __construct(private readonly OrderStatusMapper $statuses) {}

    public function reclassify(): RealizedReclassifyResult
    {
        $realized = $this->statuses->realizedStatuses();

        return new RealizedReclassifyResult(
            $this->flip(Order::withTrashed()->whereIn('status', $realized)->where('is_realized', false), true),
            $this->flip(Order::withTrashed()->whereNotIn('status', $realized)->where('is_realized', true), false),
        );
    }

    /** @param  Builder<Order>  $disagreeing */
    private function flip($disagreeing, bool $to): int
    {
        $changed = 0;

        $disagreeing->orderBy('id')->chunkById(self::CHUNK, function (Collection $orders) use ($to, &$changed): void {
            Order::withTrashed()->whereKey($orders->modelKeys())->update(['is_realized' => $to]);
            $changed += $orders->count();

            foreach ($orders as $order) {
                OrderSynced::dispatch($order->id, $order->customer_id);
            }
        });

        return $changed;
    }
}
