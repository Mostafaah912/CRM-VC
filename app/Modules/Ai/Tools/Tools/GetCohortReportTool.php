<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetCohortReportTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_cohort_report';
    }

    public function description(): string
    {
        return 'Cohort matrix: per Jalali first-purchase month, cohort size and retention percent for each month-since-first-purchase (mature periods only; immature are null), plus cumulative revenue in Toman at the last mature period. Cohorts under 5 customers show no size or retention.';
    }

    public function properties(): array
    {
        return [
            'last_cohorts' => ['type' => 'integer', 'description' => 'Most recent cohorts to show, 1 to 12. Default 6.'],
            'max_period' => ['type' => 'integer', 'description' => 'Last period to show, 1 to 12. Default 6.'],
        ];
    }

    public function rules(): array
    {
        return ['last_cohorts' => 'integer|min:1|max:12', 'max_period' => 'integer|min:1|max:12'];
    }

    public function run(array $arguments): array
    {
        $lastCohorts = (int) ($arguments['last_cohorts'] ?? 6);
        $maxPeriod = (int) ($arguments['max_period'] ?? 6);
        $db = $this->db->connection();
        $months = $db->table('cohort_snapshots')->distinct()->orderByDesc('cohort_month')->limit($lastCohorts)->pluck('cohort_month')->all();

        if ($months === []) {
            return ['data_available' => false, 'cohorts' => []];
        }

        $rows = $db->table('cohort_snapshots')->whereIn('cohort_month', $months)->where('period_number', '<=', $maxPeriod)
            ->orderBy('cohort_month')->orderBy('period_number')
            ->get(['cohort_month', 'period_number', 'cohort_size', 'retention_rate', 'is_mature', 'cumulative_revenue']);

        $cohorts = [];

        foreach ($rows->groupBy('cohort_month') as $month => $periods) {
            $size = (int) $periods->first()->cohort_size;
            $small = $size < SmallGroupMask::min();
            $retention = [];
            $lastMature = null;

            foreach ($periods as $p) {
                if ($p->period_number >= 1) {
                    $retention[(int) $p->period_number] = $small || ! $p->is_mature ? null : round((float) $p->retention_rate * 100, 2);
                }

                if ($p->is_mature) {
                    $lastMature = $p;
                }
            }

            $cohorts[] = [
                'cohort_month' => (string) $month,
                'cohort_size' => SmallGroupMask::count($size),
                'retention_percent_by_period' => $small ? null : $retention,
                'cumulative_revenue' => $small || $lastMature === null ? null : ToolFormat::toman($lastMature->cumulative_revenue),
            ];
        }

        return ['cohorts' => $cohorts];
    }
}
