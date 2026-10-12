<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetChurnReportTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_churn_report';
    }

    public function description(): string
    {
        return 'Customers per churn-risk level (low, medium, high, lost) with average risk score and recency, the value at risk (estimated CLV, falling back to historical, of high+lost customers, in Toman) and the store thresholds in days used by the latest metrics run.';
    }

    public function run(array $arguments): array
    {
        $db = $this->db->connection();
        $rows = $db->table('customer_metrics')->whereNotNull('churn_risk_level')->groupBy('churn_risk_level')
            ->selectRaw('churn_risk_level as level, count(*) as customers, avg(churn_risk_score) as score, avg(recency_days) as recency, sum(coalesce(clv_estimated, clv_historical)) as clv')
            ->get();
        $total = (int) $rows->sum('customers');
        $atRisk = $rows->whereIn('level', ['high', 'lost']);
        $mapped = $rows->map(fn ($r) => [
            'level' => (string) $r->level,
            'customers' => (int) $r->customers,
            'share_percent' => ToolFormat::percent($r->customers, $total),
            'avg_risk_score' => round((float) $r->score, 1),
            'avg_recency_days' => ToolFormat::int($r->recency),
        ])->values()->all();

        $run = $db->table('metric_runs')->whereNotNull('finished_at')->whereNull('error')->whereNotNull('thresholds')->orderByDesc('id')->first(['thresholds', 'finished_at']);
        $thresholds = $run === null ? [] : (array) json_decode((string) $run->thresholds, true);

        return [
            'classified_customers' => $total,
            'levels' => SmallGroupMask::apply($mapped, 'level'),
            'value_at_risk' => (int) $atRisk->sum('customers') >= SmallGroupMask::min() ? ToolFormat::toman($atRisk->sum('clv')) : null,
            'thresholds_days' => array_intersect_key(array_map('intval', array_filter($thresholds, 'is_numeric')), array_flip(['p50', 'p75', 'p90'])),
        ];
    }
}
