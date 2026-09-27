<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use App\Modules\Metrics\Enums\ChurnRiskLevel;
use Illuminate\Support\Facades\DB;

/**
 * P6-06's dashboard "churn risk distribution + value at risk" widget — read straight from
 * customer_metrics, owned by this module, same reasoning as RfmPageService::segmentDistribution()
 * (Analytics may only reach Metrics through a public Service, never its Enums).
 */
final class ChurnDistributionService
{
    /** @return array{distribution: array<string, int>, value_at_risk: int} */
    public function summary(): array
    {
        return [
            'distribution' => $this->distribution(),
            'value_at_risk' => $this->valueAtRisk(),
        ];
    }

    /** @return array<string, int> every churn risk level, plus 'none' for a not-yet-scored customer */
    private function distribution(): array
    {
        $counts = DB::table('customer_metrics')->selectRaw('churn_risk_level, count(*) as n')->groupBy('churn_risk_level')->pluck('n', 'churn_risk_level');

        $distribution = [];

        foreach (ChurnRiskLevel::cases() as $level) {
            $distribution[$level->value] = (int) ($counts[$level->value] ?? 0);
        }

        $distribution['none'] = (int) ($counts[''] ?? 0);

        return $distribution;
    }

    /**
     * Sum of estimated CLV (falling back to historical CLV where no estimate exists yet, PRD §14:
     * "clv_estimated is NULL when total_orders < 2, never fabricated") over every high/lost-risk
     * customer — PRD names this widget "ارزش در خطر" (value at risk) without spelling out the exact
     * figure; this is the reasoned default, not a PRD-specified formula.
     */
    private function valueAtRisk(): int
    {
        return (int) DB::table('customer_metrics')
            ->whereIn('churn_risk_level', [ChurnRiskLevel::High->value, ChurnRiskLevel::Lost->value])
            ->selectRaw('COALESCE(SUM(COALESCE(clv_estimated, clv_historical)), 0)::bigint AS total')
            ->value('total');
    }
}
