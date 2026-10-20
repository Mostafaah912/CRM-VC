<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetSegmentSummaryTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_segment_summary';
    }

    public function description(): string
    {
        return 'Active customer segments with member count, when last evaluated, and (for segments of at least 5 members) average recency, average monetary and total revenue of the members. Smaller segments show only "<5".';
    }

    public function properties(): array
    {
        return [
            'segment_id' => ['type' => 'integer', 'description' => 'Only this segment (from an earlier result). Optional.'],
            'limit' => ['type' => 'integer', 'description' => 'Segments to return, 1 to 30. Default 15.'],
        ];
    }

    public function rules(): array
    {
        return ['segment_id' => 'integer|min:1', 'limit' => 'integer|min:1|max:30'];
    }

    public function run(array $arguments): array
    {
        $limit = (int) ($arguments['limit'] ?? 15);
        $db = $this->db->connection();
        $segments = $db->table('segments')->whereNull('deleted_at')->where('is_active', true)
            ->when(isset($arguments['segment_id']), fn ($q) => $q->where('id', (int) $arguments['segment_id']))
            ->orderByDesc('member_count')->orderBy('id')->limit($limit)
            ->get(['id', 'name', 'type', 'member_count', 'last_evaluated_at']);

        $stats = $segments->isEmpty() ? collect() : $db->table('segment_members')
            ->join('customer_metrics', 'customer_metrics.customer_id', '=', 'segment_members.customer_id')
            ->whereIn('segment_members.segment_id', $segments->pluck('id')->all())
            ->groupBy('segment_members.segment_id')
            ->havingRaw('count(*) >= ?', [SmallGroupMask::min()])
            ->selectRaw('segment_members.segment_id as segment_id, count(*) as members, avg(customer_metrics.recency_days) as recency, avg(customer_metrics.monetary) as monetary, sum(customer_metrics.total_revenue) as revenue')
            ->get()->keyBy('segment_id');

        return ['segments' => $segments->map(function ($s) use ($stats) {
            $stat = $stats->get($s->id);

            return [
                'segment_id' => (int) $s->id,
                'name' => (string) $s->name,
                'type' => (string) $s->type,
                'members' => SmallGroupMask::count((int) $s->member_count),
                'last_evaluated' => $s->last_evaluated_at === null ? null : ToolFormat::date(substr((string) $s->last_evaluated_at, 0, 10)),
                'avg_recency_days' => $stat === null ? null : ToolFormat::int($stat->recency),
                'avg_monetary' => $stat === null ? null : ToolFormat::toman($stat->monetary),
                'total_revenue' => $stat === null ? null : ToolFormat::toman($stat->revenue),
            ];
        })->all()];
    }
}
