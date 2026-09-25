<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Core\Services\AuditService;
use App\Modules\Orders\Exceptions\RefundSyncException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orders' public refund API (PRD §10 idempotency #4: recompute, never increment). sync() takes the COMPLETE refund
 * set Woo currently reports for ONE order and makes the local state equal to it, in ONE transaction:
 *
 *  - refunds are upserted by woo_refund_id (UNIQUE); one that Woo no longer lists is deleted, after an audit entry
 *    with its id, amount and order (so refunded_total stays derived truth);
 *  - orders.refunded_total = SUM of the persisted refunds — recomputed and SET, never incremented;
 *  - orders.is_fully_refunded = refunded_total > 0 AND refunded_total >= total;
 *    refunds.is_full           = amount > 0 AND amount >= order total (both recomputed every time, since total can change);
 *  - order_items.refunded_qty/refunded_amount are SET from the refund lines of the same complete set, attributed
 *    only by Woo's own link (originalWooItemId); a line without it, or pointing at an item we do not have, is
 *    left unattributed and logged — never guessed from product, variation or SKU. No refund lines are stored
 *    anywhere, so the item figures can only be rebuilt from the full set passed in.
 *
 * Nothing here calls Woo: a caller that could not read the whole set must not call this (deleting on a partial
 * set would be wrong). Any failure rolls back everything, the audit entries included.
 */
final class RefundService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  list<RefundInput>  $refunds
     *
     * @throws RefundSyncException
     */
    public function sync(int $wooOrderId, array $refunds): RefundSyncSummary
    {
        return DB::transaction(function () use ($wooOrderId, $refunds): RefundSyncSummary {
            $order = Order::withTrashed()->where('woo_order_id', $wooOrderId)->lockForUpdate()->first()
                ?? throw RefundSyncException::orderNotFound($wooOrderId);

            $incoming = [];

            foreach ($refunds as $refund) {
                $incoming[$refund->wooRefundId] = $refund;
            }

            foreach ($incoming as $refund) {
                $this->upsertRefund($order, $refund);
            }

            $removed = $this->removeMissing($order, array_keys($incoming));
            $this->recomputeOrder($order);
            $unattributed = $this->recomputeItems($order, $incoming);

            return new RefundSyncSummary(count($incoming), $removed, $unattributed);
        });
    }

    /**
     * Which of these Woo orders already hold at least one refund row — in the order asked, each once. Read-only: it is how
     * the sync notices an order whose refunds Woo may have deleted (the order's own payload then lists none). Soft-deleted
     * orders count, as in sync().
     *
     * @param  list<int>  $wooOrderIds
     * @return list<int>
     */
    public function wooOrderIdsWithRefunds(array $wooOrderIds): array
    {
        $asked = array_values(array_unique($wooOrderIds));

        if ($asked === []) {
            return [];
        }

        $holding = array_map(intval(...), Order::withTrashed()->whereIn('woo_order_id', $asked)->whereHas('refunds')->pluck('woo_order_id')->all());

        return array_values(array_filter($asked, fn (int $wooOrderId): bool => in_array($wooOrderId, $holding, true)));
    }

    private function upsertRefund(Order $order, RefundInput $input): void
    {
        $refund = Refund::query()->where('woo_refund_id', $input->wooRefundId)->lockForUpdate()->first();

        if ($refund !== null && $refund->order_id !== $order->id) {
            throw RefundSyncException::refundUnderAnotherOrder($order->woo_order_id, $input->wooRefundId);
        }

        $refund ??= new Refund(['woo_refund_id' => $input->wooRefundId]);
        $refund->fill([
            'order_id' => $order->id,
            'amount' => $input->amount,
            'reason' => $input->reason,
            'refunded_at' => $input->refundedAt,
        ]);
        $refund->save();
    }

    /**
     * @param  list<int>  $keepWooRefundIds
     */
    private function removeMissing(Order $order, array $keepWooRefundIds): int
    {
        $missing = Refund::query()->where('order_id', $order->id)->whereNotIn('woo_refund_id', $keepWooRefundIds)->get();

        foreach ($missing as $refund) {
            $this->audit->recordSystem(
                action: 'refund.deleted',
                auditableType: Refund::class,
                auditableId: $refund->id,
                before: [
                    'woo_refund_id' => $refund->woo_refund_id,
                    'amount' => $refund->amount,
                    'order_id' => $order->id,
                    'woo_order_id' => $order->woo_order_id,
                ],
                source: 'sync',
            );

            $refund->delete();
        }

        return $missing->count();
    }

    private function recomputeOrder(Order $order): void
    {
        $refundedTotal = (int) Refund::query()->where('order_id', $order->id)->sum('amount');

        $order->refunded_total = $refundedTotal;
        $order->is_fully_refunded = $refundedTotal > 0 && $refundedTotal >= $order->total;

        if ($order->isDirty()) {
            $order->save();
        }

        foreach (Refund::query()->where('order_id', $order->id)->get() as $refund) {
            $refund->is_full = $refund->amount > 0 && $refund->amount >= $order->total;

            if ($refund->isDirty()) {
                $refund->save();
            }
        }
    }

    /**
     * @param  array<int, RefundInput>  $incoming
     * @return int lines that could not be attributed to an order item
     */
    private function recomputeItems(Order $order, array $incoming): int
    {
        $items = OrderItem::query()->where('order_id', $order->id)->get();
        $known = [];

        foreach ($items as $item) {
            if ($item->woo_item_id !== null) {
                $known[$item->woo_item_id] = true;
            }
        }

        $quantity = [];
        $amount = [];
        $unattributed = 0;

        foreach ($incoming as $refund) {
            foreach ($refund->items as $line) {
                if ($line->originalWooItemId === null || ! isset($known[$line->originalWooItemId])) {
                    $unattributed++;
                    Log::warning('Refund line cannot be attributed to an order item; item-level refund figures are not computed for it', [
                        'woo_order_id' => $order->woo_order_id,
                        'woo_refund_id' => $refund->wooRefundId,
                        'woo_refund_item_id' => $line->wooItemId,
                        'original_woo_item_id' => $line->originalWooItemId,
                    ]);

                    continue;
                }

                $quantity[$line->originalWooItemId] = ($quantity[$line->originalWooItemId] ?? 0) + $line->quantity;
                $amount[$line->originalWooItemId] = ($amount[$line->originalWooItemId] ?? 0) + $line->amount;
            }
        }

        foreach ($items as $item) {
            $item->refunded_qty = $item->woo_item_id === null ? 0 : ($quantity[$item->woo_item_id] ?? 0);
            $item->refunded_amount = $item->woo_item_id === null ? 0 : ($amount[$item->woo_item_id] ?? 0);

            if ($item->isDirty()) {
                $item->save();
            }
        }

        return $unattributed;
    }
}
