<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetCustomerSummaryTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_customer_summary';
    }

    public function description(): string
    {
        return 'Whole-customer-base summary (never an individual): customers with orders, one-time vs repeat, repeat rate, average orders, AOV, lifetime value and recency, and how confident the CLV estimates are. No names, phones, emails, addresses or ids.';
    }

    public function run(array $arguments): array
    {
        $db = $this->db->connection();
        $r = $db->table('customer_metrics')->where('total_orders', '>', 0)->selectRaw(
            'count(*) as customers, count(*) filter (where total_orders >= 2) as repeaters, avg(total_orders) as orders, avg(aov) as aov, '
            .'avg(clv_historical) as clv, sum(total_revenue) as revenue, avg(recency_days) as recency',
        )->first();
        $confidence = $db->table('customer_metrics')->where('total_orders', '>', 0)->whereNotNull('clv_confidence')->groupBy('clv_confidence')
            ->selectRaw('clv_confidence as level, count(*) as customers')->get()
            ->sortBy(fn ($c) => array_search((string) $c->level, ['high', 'medium', 'low'], true))
            ->map(fn ($c) => ['level' => (string) $c->level, 'customers' => (int) $c->customers])->values()->all();

        $customers = (int) $r->customers;

        if ($customers < SmallGroupMask::min()) {
            return ['customers_with_orders' => SmallGroupMask::count($customers)];
        }

        return [
            'customers_with_orders' => $customers,
            'one_time_customers' => $customers - (int) $r->repeaters,
            'repeat_customers' => (int) $r->repeaters,
            'repeat_rate_percent' => ToolFormat::percent($r->repeaters, $customers),
            'one_time_customers_percent' => ToolFormat::percent($customers - (int) $r->repeaters, $customers),
            'avg_orders' => round((float) $r->orders, 2),
            'avg_aov' => ToolFormat::toman($r->aov),
            'avg_clv_historical' => ToolFormat::toman($r->clv),
            'total_revenue' => ToolFormat::toman($r->revenue),
            'avg_recency_days' => ToolFormat::int($r->recency),
            'clv_confidence' => SmallGroupMask::apply($confidence, 'level'),
        ];
    }
}
