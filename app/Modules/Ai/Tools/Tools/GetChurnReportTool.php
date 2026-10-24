<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;
use Illuminate\Support\Collection;

final class GetChurnReportTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_churn_report';
    }

    public function description(): string
    {
        return 'Customers per churn-risk level (low, medium, high, lost) with average risk score and recency, the value at risk as two figures (lifetime revenue in Toman of the high-risk customers and of the lost customers, separately — not a CLV projection) and the store thresholds in days used by the latest metrics run.';
    }

    public function run(array $arguments): array
    {
        $db = $this->db->connection();
        $rows = $db->table('customer_metrics')->whereNotNull('churn_risk_level')->groupBy('churn_risk_level')
            ->selectRaw('churn_risk_level as level, count(*) as customers, avg(churn_risk_score) as score, avg(recency_days) as recency, sum(total_revenue) as revenue')
            ->get()
            ->sortBy(fn ($r) => array_search((string) $r->level, ['low', 'medium', 'high', 'lost'], true))->values();
        $total = (int) $rows->sum('customers');
        $mapped = $rows->map(fn ($r) => [
            'level' => (string) $r->level,
            'customers' => (int) $r->customers,
            'share_percent' => ToolFormat::percent($r->customers, $total),
            'avg_risk_score' => round((float) $r->score, 1),
            'avg_recency_days' => ToolFormat::int($r->recency),
        ])->values()->all();

        $risk = $db->table('customer_metrics')->whereIn('churn_risk_level', ['high', 'lost'])->whereNotNull('clv_confidence')->groupBy('clv_confidence')
            ->selectRaw('clv_confidence as level, count(*) as customers')->get()
            ->sortBy(fn ($c) => array_search((string) $c->level, ['high', 'medium', 'low'], true))->values();
        $riskTotal = (int) $risk->sum('customers');
        $riskConfidence = $risk->map(fn ($c) => ['level' => (string) $c->level, 'customers' => (int) $c->customers, 'share_percent' => ToolFormat::percent($c->customers, $riskTotal)])->all();

        $run = $db->table('metric_runs')->whereNotNull('finished_at')->whereNull('error')->whereNotNull('thresholds')->orderByDesc('id')->first(['thresholds', 'finished_at']);
        $thresholds = $run === null ? [] : (array) json_decode((string) $run->thresholds, true);

        return [
            'classified_customers' => $total,
            'levels' => SmallGroupMask::apply($mapped, 'level'),
            // Two figures, each hidden on its own when its group is below the small-group floor.
            'value_at_risk' => [
                'high' => $this->lifetimeRevenueOf($rows, 'high'),
                'lost' => $this->lifetimeRevenueOf($rows, 'lost'),
            ],
            'value_at_risk_means' => 'lifetime_revenue_of_high_risk_and_of_lost_churn_customers_not_a_confirmed_loss',
            'value_at_risk_clv_confidence' => SmallGroupMask::apply($riskConfidence, 'level'),
            'thresholds_days' => array_intersect_key(array_map('intval', array_filter($thresholds, 'is_numeric')), array_flip(['p50', 'p75', 'p90'])),
        ];
    }

    /** @param  Collection<int, \stdClass>  $rows */
    private function lifetimeRevenueOf($rows, string $level): ?int
    {
        $row = $rows->firstWhere('level', $level);

        return $row !== null && (int) $row->customers >= SmallGroupMask::min() ? ToolFormat::toman((int) $row->revenue) : null;
    }
}
