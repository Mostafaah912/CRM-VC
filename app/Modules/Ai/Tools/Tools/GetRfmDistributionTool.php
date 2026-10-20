<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetRfmDistributionTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_rfm_distribution';
    }

    public function description(): string
    {
        return 'Customers per RFM segment (champion, loyal, at_risk, ...) with share of scored customers, average recency/frequency/monetary and total revenue. Groups under 5 customers are merged.';
    }

    public function run(array $arguments): array
    {
        $rows = $this->db->connection()->table('customer_metrics')->whereNotNull('rfm_segment')
            ->groupBy('rfm_segment')->orderByRaw('count(*) desc, rfm_segment')
            ->selectRaw('rfm_segment as segment, count(*) as customers, avg(recency_days) as recency, avg(frequency) as frequency, avg(monetary) as monetary, sum(total_revenue) as revenue')
            ->get();

        $total = (int) $rows->sum('customers');
        $mapped = $rows->map(fn ($r) => [
            'segment' => (string) $r->segment,
            'customers' => (int) $r->customers,
            'share_percent' => ToolFormat::percent($r->customers, $total),
            'avg_recency_days' => ToolFormat::int($r->recency),
            'avg_frequency' => round((float) $r->frequency, 2),
            'avg_monetary' => ToolFormat::toman($r->monetary),
            'total_revenue' => ToolFormat::toman($r->revenue),
        ])->values()->all();

        return ['scored_customers' => $total, 'segments' => SmallGroupMask::apply($mapped, 'segment')];
    }
}
