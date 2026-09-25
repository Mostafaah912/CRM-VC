<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use App\Modules\Metrics\Models\MetricRun;
use Illuminate\Support\Facades\DB;

/**
 * PRD §14 step 1: the store's real distribution of days between a customer's realized orders —
 * never a hardcoded churn threshold (CLAUDE.md §4). Only intervals between 0 and 730 days count;
 * a first order (no prior order to subtract) has no interval and a gap over two years is data
 * noise (a returning customer after that long is "new" again, not evidence of a slow cycle).
 *
 * Low-sample guard: a store early in its life, or a test/demo dataset, may not have 200 realized
 * intervals yet — below that, `config('metrics.fallback_percentiles')` (60/120/210) stands in so a
 * churn threshold is never computed from a handful of orders that happen to be unrepresentative.
 */
final class ChurnThresholdService
{
    private const MIN_SAMPLE_SIZE = 200;

    /** @return array{p50: int, p75: int, p90: int, sample_size: int} */
    public function percentiles(): array
    {
        $row = DB::selectOne(<<<'SQL'
            WITH intervals AS (
                SELECT
                    customer_id,
                    EXTRACT(EPOCH FROM (
                        ordered_at - LAG(ordered_at) OVER (PARTITION BY customer_id ORDER BY ordered_at)
                    )) / 86400.0 AS days
                FROM orders
                WHERE is_realized = true
                  AND deleted_at IS NULL
                  AND is_fully_refunded = false
            )
            SELECT
                percentile_cont(0.50) WITHIN GROUP (ORDER BY days) AS p50,
                percentile_cont(0.75) WITHIN GROUP (ORDER BY days) AS p75,
                percentile_cont(0.90) WITHIN GROUP (ORDER BY days) AS p90,
                COUNT(*)::integer AS sample_size
            FROM intervals
            WHERE days > 0 AND days <= 730
            SQL);

        $sampleSize = (int) $row->sample_size;

        if ($sampleSize < self::MIN_SAMPLE_SIZE) {
            /** @var array{p50: int, p75: int, p90: int} $fallback */
            $fallback = config('metrics.fallback_percentiles');

            return [...$fallback, 'sample_size' => $sampleSize];
        }

        return [
            'p50' => (int) round($row->p50),
            'p75' => (int) round($row->p75),
            'p90' => (int) round($row->p90),
            'sample_size' => $sampleSize,
        ];
    }

    /** @param array{p50: int, p75: int, p90: int, sample_size: int} $thresholds */
    public function saveToRun(MetricRun $run, array $thresholds): void
    {
        $run->update(['thresholds' => $thresholds]);
    }
}
