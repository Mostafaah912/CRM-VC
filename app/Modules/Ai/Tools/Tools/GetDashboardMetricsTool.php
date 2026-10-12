<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\ToolFormat;
use Illuminate\Database\ConnectionInterface;

final class GetDashboardMetricsTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_dashboard_metrics';
    }

    public function description(): string
    {
        return 'Headline store KPIs (orders, revenue in Toman, refunds, net revenue, AOV, new vs repeat customers) for the last N days, compared with the previous N days, plus the size and repeat rate of the customer base.';
    }

    public function properties(): array
    {
        return ['days' => ['type' => 'integer', 'description' => 'Window length in days, 1 to 366. Default 30.']];
    }

    public function rules(): array
    {
        return ['days' => 'integer|min:1|max:366'];
    }

    public function run(array $arguments): array
    {
        $days = (int) ($arguments['days'] ?? 30);
        $db = $this->db->connection();
        $end = Period::end($db);

        if ($end === null) {
            return ['data_available' => false];
        }

        $start = $end->subDays($days - 1);
        $prevEnd = $start->subDay();
        $prevStart = $prevEnd->subDays($days - 1);
        $current = $this->sums($db, $start->toDateString(), $end->toDateString());
        $previous = $this->sums($db, $prevStart->toDateString(), $prevEnd->toDateString());

        $base = $db->table('customer_metrics')
            ->where('total_orders', '>', 0)
            ->selectRaw('count(*) as customers, count(*) filter (where total_orders >= 2) as repeaters')
            ->first();

        return [
            'period' => ['days' => $days, 'from' => ToolFormat::date($start->toDateString()), 'to' => ToolFormat::date($end->toDateString())],
            'current' => $current,
            'previous' => $previous,
            'change_percent' => [
                'orders' => $this->change($current['orders'], $previous['orders']),
                'net_revenue' => $this->change($current['net_revenue'], $previous['net_revenue']),
                'aov' => $this->change($current['aov'], $previous['aov']),
            ],
            'customer_base' => [
                'customers_with_orders' => (int) $base->customers,
                'repeat_customers' => (int) $base->repeaters,
                'repeat_rate_percent' => ToolFormat::percent($base->repeaters, $base->customers),
            ],
        ];
    }

    /** @return array<string, int> */
    private function sums(ConnectionInterface $db, string $from, string $to): array
    {
        $r = $db->table('daily_metrics')->whereBetween('date', [$from, $to])->selectRaw(
            'coalesce(sum(orders_count), 0) as orders, coalesce(sum(revenue), 0) as revenue, coalesce(sum(refunds), 0) as refunds, '
            .'coalesce(sum(net_revenue), 0) as net_revenue, coalesce(sum(product_revenue), 0) as product_revenue, '
            .'coalesce(sum(shipping_revenue), 0) as shipping_revenue, coalesce(sum(customers_new), 0) as customers_new, '
            .'coalesce(sum(customers_repeat), 0) as customers_repeat, coalesce(sum(revenue_new), 0) as revenue_new, coalesce(sum(revenue_repeat), 0) as revenue_repeat',
        )->first();
        $orders = ToolFormat::int($r->orders);

        return [
            'orders' => $orders,
            'revenue' => ToolFormat::toman($r->revenue),
            'refunds' => ToolFormat::toman($r->refunds),
            'net_revenue' => ToolFormat::toman($r->net_revenue),
            'product_revenue' => ToolFormat::toman($r->product_revenue),
            'shipping_revenue' => ToolFormat::toman($r->shipping_revenue),
            'aov' => $orders === 0 ? 0 : ToolFormat::toman((float) $r->net_revenue / $orders),
            'customer_days_new' => ToolFormat::int($r->customers_new),
            'customer_days_repeat' => ToolFormat::int($r->customers_repeat),
            'revenue_new' => ToolFormat::toman($r->revenue_new),
            'revenue_repeat' => ToolFormat::toman($r->revenue_repeat),
        ];
    }

    private function change(int $now, int $before): ?float
    {
        return $before === 0 ? null : round(($now - $before) / $before * 100, 2);
    }
}
