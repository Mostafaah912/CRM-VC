<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerShowData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Customer 360 (PRD §18): one customer, read-only, in SIX queries — the budget, exactly — SEVEN when
 * a customer_metrics row exists (P4-08 adds the "is this stale" check, see isMetricsStale()):
 *
 *   Q1 the customer (a soft-deleted one is a 404)          Q4 the last five products bought, grouped
 *   Q2 its customer_metrics row, if one was computed        Q5 how many orders it has in all
 *   Q3 its last five orders                                 Q6 the first page of its timeline (CustomerTimelineService)
 *                                                            Q7 (only if Q2 found a row) the latest completed metric_run
 *
 * The timeline's later pages are not part of this page: the browser asks GET /customers/{customer}/timeline with the cursor.
 * Metrics are only READ from customer_metrics — never computed here. Orders, items and metrics belong to other modules; this
 * service reads their tables with the query builder and never `use`s their classes (a documented exception to the PRD §07
 * dependency table, pinned by CustomerShowBoundaryTest). Nothing here writes, dispatches or logs.
 */
class CustomerShowService
{
    public const RECENT = 5;

    private const METRICS_COLUMNS = [
        'total_orders', 'total_revenue', 'aov', 'first_order_at', 'last_order_at', 'r_score', 'f_score', 'm_score',
        'rfm_score', 'rfm_segment', 'clv_historical', 'clv_estimated', 'clv_confidence',
        'churn_risk_score', 'churn_risk_level', 'churn_reason', 'expected_next_order_at', 'computed_at',
    ];

    public function __construct(private readonly CustomerTimelineService $timeline) {}

    public function show(int $customerId): CustomerShowData
    {
        $customer = Customer::query()->select(CustomerShowData::CUSTOMER_COLUMNS)->findOrFail($customerId);

        $metrics = DB::table('customer_metrics')->where('customer_id', $customer->id)->first(self::METRICS_COLUMNS);
        $metricsStale = $metrics !== null && $this->isMetricsStale($metrics);

        $orders = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['woo_order_id', 'number', 'status', 'total', 'ordered_at']);

        $products = $this->recentProducts($customer->id);

        $ordersTotal = DB::table('orders')->where('customer_id', $customer->id)->whereNull('deleted_at')->count();

        $timeline = $this->timeline->page($customer);

        return new CustomerShowData(
            $customer,
            $metrics === null ? null : (array) $metrics,
            $metricsStale,
            array_values($orders->map(fn (object $row): array => (array) $row)->all()),
            $ordersTotal,
            $products,
            $timeline,
        );
    }

    /**
     * P4-08, Gate "Sprint 3's 6 queries" knowingly extended by one: whether a LATER metric run
     * finished after this row was computed — never derived from this row's own metric_run_id, since
     * a customer left out of a 'dirty' run (P4-07) still points at their last real run and would
     * otherwise never look stale. Only ever runs when a customer_metrics row exists at all.
     *
     * A "completed" run is identified structurally (finished_at set, no error) rather than by the
     * literal string 'completed': Customers cannot `use` Metrics\Enums\MetricRunStatus (PRD §07's
     * module dependency table), and a bare status string here would also be indistinguishable, to
     * CLAUDE.md §3's "never hardcode an order status" arch check, from a Woo order status literal.
     */
    private function isMetricsStale(\stdClass $metrics): bool
    {
        if ($metrics->computed_at === null) {
            return false;
        }

        $lastCompletedRunAt = DB::table('metric_runs')->whereNotNull('finished_at')->whereNull('error')->max('finished_at');

        if ($lastCompletedRunAt === null) {
            return false;
        }

        return CarbonImmutable::parse((string) $metrics->computed_at, 'UTC')
            ->lt(CarbonImmutable::parse((string) $lastCompletedRunAt, 'UTC'));
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
