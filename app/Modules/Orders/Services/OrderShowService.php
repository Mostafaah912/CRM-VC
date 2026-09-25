<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderShowData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * P3-06, read-only: one order's detail, in at most THREE queries — Q1 the order, Q2 its items, Q3 its customer (skipped when
 * there is none: `needs_phone_review` orders have no customer_id — see PRD §21/P2-13 — and never error for it). All three are
 * plain query-builder reads of `orders`, `order_items` and `customers`; the Orders module never `use`s a class of Customers.
 * An order id that does not exist is a 404 — orders have no soft delete.
 */
final class OrderShowService
{
    private const ORDER_COLUMNS = [
        'id', 'woo_order_id', 'status', 'total', 'subtotal', 'discount_total', 'shipping_total', 'tax_total',
        'refunded_total', 'is_realized', 'is_fully_refunded', 'needs_phone_review', 'ordered_at', 'customer_id',
    ];

    private const ITEM_COLUMNS = ['name_snapshot', 'sku', 'qty', 'unit_price', 'line_total'];

    private const CUSTOMER_COLUMNS = ['id', 'display_name', 'phone_normalized'];

    public function show(int $orderId): OrderShowData
    {
        $order = DB::table('orders')->where('id', $orderId)->first(self::ORDER_COLUMNS);

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        $order = (array) $order;

        $items = DB::table('order_items')
            ->where('order_id', $order['id'])
            ->orderBy('id')
            ->get(self::ITEM_COLUMNS)
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
        /** @var list<array<string, mixed>> $items */
        $customer = $order['customer_id'] === null
            ? null
            : (array) DB::table('customers')->where('id', $order['customer_id'])->first(self::CUSTOMER_COLUMNS);

        return new OrderShowData($order, $items, $customer === [] ? null : $customer);
    }
}
