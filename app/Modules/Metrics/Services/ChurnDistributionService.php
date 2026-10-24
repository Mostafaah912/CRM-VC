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
    /** @return array{distribution: array<string, int>, value_at_risk: array{high: int, lost: int}} */
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
     * The dashboard's "ارزش در خطر", as TWO figures (P8 decision): the lifetime revenue (`customer_metrics.total_revenue`, Toman) of the
     * customers currently at `high` churn risk, and of those already `lost` — kept apart because "still saveable" and "already gone" are
     * different decisions. It is deliberately NOT built on `clv_estimated`: that projection (aov x margin x 365 / purchase_cycle_days x horizon)
     * explodes when a customer's cycle is minutes long (two orders in one sitting), and on staging 100 such customers made up ~98% of a 32.8
     * bn figure that exceeded the whole store's revenue. Revenue is a fact already in the orders; PRD names the widget without a formula.
     *
     * @return array{high: int, lost: int}
     */
    private function valueAtRisk(): array
    {
        $totals = DB::table('customer_metrics')
            ->whereIn('churn_risk_level', [ChurnRiskLevel::High->value, ChurnRiskLevel::Lost->value])
            ->selectRaw('churn_risk_level AS level, COALESCE(SUM(total_revenue), 0)::bigint AS total')
            ->groupBy('churn_risk_level')
            ->pluck('total', 'level');

        return [
            'high' => (int) ($totals[ChurnRiskLevel::High->value] ?? 0),
            'lost' => (int) ($totals[ChurnRiskLevel::Lost->value] ?? 0),
        ];
    }
}
