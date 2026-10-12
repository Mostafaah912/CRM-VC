<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetRetentionReportTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_retention_report';
    }

    public function description(): string
    {
        return 'Blended retention: for each month-since-first-purchase (1 to max_period), the share of cohort customers who ordered again, pooled over the last N cohorts. Only fully elapsed (mature) periods count; cohorts under 5 customers are excluded.';
    }

    public function properties(): array
    {
        return [
            'max_period' => ['type' => 'integer', 'description' => 'Last period (months since first purchase) to report, 1 to 12. Default 6.'],
            'last_cohorts' => ['type' => 'integer', 'description' => 'How many most recent cohorts to pool, 1 to 24. Default 12.'],
        ];
    }

    public function rules(): array
    {
        return ['max_period' => 'integer|min:1|max:12', 'last_cohorts' => 'integer|min:1|max:24'];
    }

    public function run(array $arguments): array
    {
        $maxPeriod = (int) ($arguments['max_period'] ?? 6);
        $lastCohorts = (int) ($arguments['last_cohorts'] ?? 12);
        $db = $this->db->connection();

        $months = $db->table('cohort_snapshots')->distinct()->orderByDesc('cohort_month')->limit($lastCohorts)->pluck('cohort_month')->all();

        if ($months === []) {
            return ['data_available' => false, 'periods' => []];
        }

        $rows = $db->table('cohort_snapshots')->whereIn('cohort_month', $months)->where('is_mature', true)
            ->where('period_number', '>=', 1)->where('period_number', '<=', $maxPeriod)
            ->where('cohort_size', '>=', SmallGroupMask::min())
            ->groupBy('period_number')->orderBy('period_number')
            ->selectRaw('period_number, count(*) as cohorts, sum(cohort_size) as size, sum(active_customers) as active')
            ->get();

        return [
            'cohorts_considered' => count($months),
            'periods' => $rows->map(fn ($r) => [
                'period' => (int) $r->period_number,
                'cohorts' => (int) $r->cohorts,
                'cohort_customers' => (int) $r->size,
                'retention_percent' => ToolFormat::percent($r->active, $r->size),
            ])->all(),
        ];
    }
}
