<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Catalog\Services\ResolvedCatalogItem;
use App\Modules\Customers\Services\CustomerIdentityService;
use App\Modules\Orders\Exceptions\OrderCustomerUnresolvedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Exceptions\InvalidPhoneException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orders' public write API for Woo sync (PRD §07: OrderService::upsert). One order and its COMPLETE item set are
 * ONE transaction: either both are written or nothing is.
 *
 *  - Identity is woo_order_id (UNIQUE). A repeat of the same order changes nothing; a soft-deleted row is updated
 *    in place, not duplicated and not restored.
 *  - The customer comes from CustomerIdentityService (normalized phone only). A name conflict is recorded there
 *    and never blocks the order; a phone that cannot be normalized cannot yield a customer, so the order is refused.
 *  - is_realized comes from OrderStatusMapper (config), never from a status string here.
 *  - Items are replaced (PRD §10: delete-then-insert keyed by order_id). Each is resolved to a catalog variation in
 *    the four steps of PRD §10; an unresolved item is stored with NULL ids and its sku + name snapshot, never rejected.
 *  - Refund figures belong to P2-07 (recomputed, never incremented): refunded_total and is_fully_refunded are not
 *    written here, and an item's refunded_qty/refunded_amount are carried over to its replacement so a resync cannot
 *    silently zero what refund sync computed.
 */
final class OrderService
{
    private const NUMBER_MAX = 40;

    private const PAYMENT_METHOD_MAX = 60;

    private const SKU_MAX = 80;

    private const NAME_MAX = 250;

    public function __construct(
        private readonly OrderStatusMapper $statuses,
        private readonly CustomerIdentityService $identities,
        private readonly CatalogService $catalog,
    ) {}

    /**
     * @return int the local order id
     *
     * @throws OrderCustomerUnresolvedException
     */
    public function upsert(OrderInput $input): int
    {
        return DB::transaction(function () use ($input): int {
            $customerId = $this->customerId($input);

            $order = Order::withTrashed()->where('woo_order_id', $input->wooOrderId)->lockForUpdate()->first()
                ?? new Order(['woo_order_id' => $input->wooOrderId]);

            $order->fill([
                'customer_id' => $customerId,
                'number' => $this->cut($input->number, self::NUMBER_MAX),
                'status' => $input->status,
                'is_realized' => $this->statuses->isRealized($input->status),
                'total' => $input->total,
                'subtotal' => array_sum(array_map(fn (OrderItemInput $item) => $item->lineSubtotal, $input->items)),
                'discount_total' => $input->discountTotal,
                'shipping_total' => $input->shippingTotal,
                'tax_total' => $input->taxTotal,
                'coupon_codes' => $input->couponCodes,
                'payment_method' => $this->cut($input->paymentMethod, self::PAYMENT_METHOD_MAX),
                'ordered_at' => $input->orderedAt,
                'paid_at' => $input->paidAt,
                'completed_at' => $input->completedAt,
                'woo_modified_at' => $input->wooModifiedAt,
                'synced_at' => now(),
            ]);
            $order->save();

            $this->replaceItems($order, $input);

            return $order->id;
        });
    }

    private function customerId(OrderInput $input): int
    {
        try {
            return $this->identities->resolveForWooOrder(
                $input->billingPhone,
                $input->billingFirstName,
                $input->billingLastName,
                $input->wooCustomerId,
                $input->wooOrderId,
            )->id;
        } catch (InvalidPhoneException) {
            throw new OrderCustomerUnresolvedException($input->wooOrderId);
        }
    }

    private function replaceItems(Order $order, OrderInput $input): void
    {
        $carried = [];

        foreach (OrderItem::query()->where('order_id', $order->id)->whereNotNull('woo_item_id')->get() as $existing) {
            $carried[$existing->woo_item_id] = [$existing->refunded_qty, $existing->refunded_amount];
        }

        OrderItem::query()->where('order_id', $order->id)->delete();

        foreach ($input->items as $item) {
            $resolved = $this->resolve($item, $input->wooOrderId);
            [$refundedQty, $refundedAmount] = $carried[$item->wooItemId] ?? [0, 0];

            OrderItem::create([
                'order_id' => $order->id,
                'woo_item_id' => $item->wooItemId,
                'product_id' => $resolved?->productId,
                'variation_id' => $resolved?->variationId,
                'sku' => $this->cut($item->sku, self::SKU_MAX),
                'name_snapshot' => $this->cut($item->name, self::NAME_MAX),
                'qty' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'line_subtotal' => $item->lineSubtotal,
                'line_total' => $item->lineTotal,
                'refunded_qty' => $refundedQty,
                'refunded_amount' => $refundedAmount,
            ]);
        }
    }

    /**
     * PRD §10, in this order and no other: (1) the Woo variation id, when there is one; (2) the Woo product id
     * together with the SKU; (3) the SKU alone; (4) nothing — the line keeps its SKU and name and is logged.
     * The database allows one variation per SKU, so no step can match twice and no tie-break exists.
     */
    private function resolve(OrderItemInput $item, int $wooOrderId): ?ResolvedCatalogItem
    {
        $resolved = null;

        if ($item->wooVariationId !== null) {
            $resolved = $this->catalog->resolveVariationByWooId($item->wooVariationId);
        }

        if ($resolved === null && $item->wooProductId !== null && $item->sku !== null) {
            $resolved = $this->catalog->resolveVariationByProductAndSku($item->wooProductId, $item->sku);
        }

        if ($resolved === null && $item->sku !== null) {
            $resolved = $this->catalog->resolveVariationBySku($item->sku);
        }

        if ($resolved === null) {
            Log::warning('Order item could not be resolved to the catalog; stored with NULL product and variation', [
                'woo_order_id' => $wooOrderId,
                'woo_item_id' => $item->wooItemId,
                'woo_product_id' => $item->wooProductId,
                'woo_variation_id' => $item->wooVariationId,
                'sku' => $item->sku,
            ]);
        }

        return $resolved;
    }

    /** Cut to the column so a long snapshot never rejects an order (revenue matters more than mapping). */
    private function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
