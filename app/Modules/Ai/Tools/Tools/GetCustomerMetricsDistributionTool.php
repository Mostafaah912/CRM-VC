<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetCustomerMetricsDistributionTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_customer_metrics_distribution';
    }

    public function description(): string
    {
        return 'Distribution of one customer metric across customers with orders: count, min, p10, p25, median, p75, p90, max and mean. Money metrics are Toman, recency in days.';
    }

    public function properties(): array
    {
        return ['metric' => ['type' => 'string', 'enum' => ['recency_days', 'frequency', 'monetary', 'aov', 'total_orders', 'clv_historical'], 'description' => 'Metric to describe. Default monetary.']];
    }

    public function rules(): array
    {
        return ['metric' => 'in:recency_days,frequency,monetary,aov,total_orders,clv_historical'];
    }

    public function run(array $arguments): array
    {
        $metric = (string) ($arguments['metric'] ?? 'monetary');
        $db = $this->db->connection();

        // One literal statement per whitelisted metric: the model's value only SELECTS a branch, it is never spliced into SQL.
        $query = $db->table('customer_metrics')->where('total_orders', '>', 0);
        $row = match ($metric) {
            'recency_days' => $query->selectRaw('count(recency_days) as n, min(recency_days) as min, percentile_cont(0.1) within group (order by recency_days) as p10, percentile_cont(0.25) within group (order by recency_days) as p25, percentile_cont(0.5) within group (order by recency_days) as p50, percentile_cont(0.75) within group (order by recency_days) as p75, percentile_cont(0.9) within group (order by recency_days) as p90, max(recency_days) as max, avg(recency_days) as mean')->first(),
            'frequency' => $query->selectRaw('count(frequency) as n, min(frequency) as min, percentile_cont(0.1) within group (order by frequency) as p10, percentile_cont(0.25) within group (order by frequency) as p25, percentile_cont(0.5) within group (order by frequency) as p50, percentile_cont(0.75) within group (order by frequency) as p75, percentile_cont(0.9) within group (order by frequency) as p90, max(frequency) as max, avg(frequency) as mean')->first(),
            'aov' => $query->selectRaw('count(aov) as n, min(aov) as min, percentile_cont(0.1) within group (order by aov) as p10, percentile_cont(0.25) within group (order by aov) as p25, percentile_cont(0.5) within group (order by aov) as p50, percentile_cont(0.75) within group (order by aov) as p75, percentile_cont(0.9) within group (order by aov) as p90, max(aov) as max, avg(aov) as mean')->first(),
            'total_orders' => $query->selectRaw('count(total_orders) as n, min(total_orders) as min, percentile_cont(0.1) within group (order by total_orders) as p10, percentile_cont(0.25) within group (order by total_orders) as p25, percentile_cont(0.5) within group (order by total_orders) as p50, percentile_cont(0.75) within group (order by total_orders) as p75, percentile_cont(0.9) within group (order by total_orders) as p90, max(total_orders) as max, avg(total_orders) as mean')->first(),
            'clv_historical' => $query->selectRaw('count(clv_historical) as n, min(clv_historical) as min, percentile_cont(0.1) within group (order by clv_historical) as p10, percentile_cont(0.25) within group (order by clv_historical) as p25, percentile_cont(0.5) within group (order by clv_historical) as p50, percentile_cont(0.75) within group (order by clv_historical) as p75, percentile_cont(0.9) within group (order by clv_historical) as p90, max(clv_historical) as max, avg(clv_historical) as mean')->first(),
            default => $query->selectRaw('count(monetary) as n, min(monetary) as min, percentile_cont(0.1) within group (order by monetary) as p10, percentile_cont(0.25) within group (order by monetary) as p25, percentile_cont(0.5) within group (order by monetary) as p50, percentile_cont(0.75) within group (order by monetary) as p75, percentile_cont(0.9) within group (order by monetary) as p90, max(monetary) as max, avg(monetary) as mean')->first(),
        };

        $n = (int) $row->n;

        if ($n < SmallGroupMask::min()) {
            return ['metric' => $metric, 'customers' => SmallGroupMask::count($n)];
        }

        $round = $metric === 'frequency' || $metric === 'total_orders' ? fn ($v) => round((float) $v, 2) : fn ($v) => ToolFormat::int($v);

        return [
            'metric' => $metric,
            'unit' => in_array($metric, ['monetary', 'aov', 'clv_historical'], true) ? 'toman' : ($metric === 'recency_days' ? 'days' : 'orders'),
            'customers' => $n,
            'min' => $round($row->min), 'p10' => $round($row->p10), 'p25' => $round($row->p25), 'median' => $round($row->p50),
            'p75' => $round($row->p75), 'p90' => $round($row->p90), 'max' => $round($row->max), 'mean' => $round($row->mean),
        ];
    }
}
