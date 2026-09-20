<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerShowData;
use Illuminate\Support\Facades\DB;

/**
 * Customer 360 (PRD §18): one customer, read-only, in FIVE queries (the budget is six; a sixth was reserved for a timeline that
 * has no table yet):
 *
 *   Q1 the customer (a soft-deleted one is a 404)          Q4 the last five products bought, grouped
 *   Q2 its customer_metrics row, if one was computed        Q5 how many orders it has in all
 *   Q3 its last five orders
 *
 * Metrics are only READ from customer_metrics — never computed here. Orders, items and metrics belong to other modules; this
 * service reads their tables with the query builder and never `use`s their classes (a documented exception to the PRD §07
 * dependency table, pinned by CustomerShowBoundaryTest). Nothing here writes, dispatches or logs.
 */
class CustomerShowService
{
    public const RECENT = 5;

    private const METRICS_COLUMNS = [
        'total_orders', 'total_revenue', 'aov', 'first_order_at', 'last_order_at', 'r_score', 'f_score', 'm_score',
        'clv_estimated', 'clv_confidence', 'churn_risk_score', 'churn_risk_level', 'churn_reason',
    ];

    public function show(int $customerId): CustomerShowData
    {
        $customer = Customer::query()->select(CustomerShowData::CUSTOMER_COLUMNS)->findOrFail($customerId);

        $metrics = DB::table('customer_metrics')->where('customer_id', $customer->id)->first(self::METRICS_COLUMNS);

        $orders = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['woo_order_id', 'number', 'status', 'total', 'ordered_at']);

        $products = $this->recentProducts($customer->id);

        $ordersTotal = DB::table('orders')->where('customer_id', $customer->id)->whereNull('deleted_at')->count();

        return new CustomerShowData(
            $customer,
            $metrics === null ? null : (array) $metrics,
            array_values($orders->map(fn (object $row): array => (array) $row)->all()),
            $ordersTotal,
            $products,
        );
    }

    /**
     * The last five distinct products the customer bought, each with how many orders held it and when last. One query reads the
     * customer's item lines from realized orders (orders.is_realized, set from config('woo.realized_statuses')), newest first, and
     * they are grouped here: raw SQL is confined to Metrics/Analytics (ArchitectureTest), and one customer's lines are tens of
     * rows, not an analytics scan. An item whose product could not be resolved (product_id NULL) still counts, grouped by the name it
     * was sold under. The name and SKU shown are the most recent line's, so a renamed product reads as the customer last saw it.
     *
     * @return list<array{name: string, sku: string|null, purchase_count: int, last_purchased_at: string}>
     */
    private function recentProducts(int $customerId): array
    {
        $lines = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.customer_id', $customerId)
            ->where('o.is_realized', true)
            ->whereNull('o.deleted_at')
            ->orderByDesc('o.ordered_at')
            ->orderByDesc('i.id')
            ->get(['i.product_id', 'i.name_snapshot', 'i.sku', 'i.order_id', 'o.ordered_at']);

        $grouped = [];

        foreach ($lines as $line) {
            $key = $line->product_id === null ? 'n:'.$line->name_snapshot : 'p:'.$line->product_id;

            // Lines arrive newest first, so the first line of a group is its latest purchase.
            $grouped[$key] ??= ['name' => (string) $line->name_snapshot, 'sku' => $line->sku === null ? null : (string) $line->sku, 'orders' => [], 'last_purchased_at' => (string) $line->ordered_at];
            $grouped[$key]['orders'][(int) $line->order_id] = true;
        }

        $products = [];

        foreach (array_slice($grouped, 0, self::RECENT) as $group) {
            $products[] = ['name' => $group['name'], 'sku' => $group['sku'], 'purchase_count' => count($group['orders']), 'last_purchased_at' => $group['last_purchased_at']];
        }

        return $products;
    }
}
