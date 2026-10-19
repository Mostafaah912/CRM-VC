<?php

declare(strict_types=1);

namespace App\Modules\Segments\Services;

use Illuminate\Support\Facades\DB;

/**
 * P7-09b: the segments ONE customer belongs to, with segment-LEVEL aggregates only (member count, average recency and monetary) — the
 * Customer 360 analyst panel's data. Nothing about any other customer leaves here, and a segment smaller than MIN_MEMBERS_FOR_STATS shows its
 * count but no averages (a tiny group's average can point at a person). Query builder only (Segments bans raw SQL); read-only.
 */
final class CustomerSegmentSummaryService
{
    public const MIN_MEMBERS_FOR_STATS = 5;

    private const MAX_SEGMENTS = 12;

    /** @return list<array{id: int, name: string, type: string, members: int, avg_recency_days: int|null, avg_monetary: int|null}> */
    public function forCustomer(int $customerId): array
    {
        $segments = DB::table('segment_members')
            ->join('segments', 'segments.id', '=', 'segment_members.segment_id')
            ->where('segment_members.customer_id', $customerId)
            ->where('segments.is_active', true)
            ->whereNull('segments.deleted_at')
            ->orderBy('segments.name')
            ->limit(self::MAX_SEGMENTS)
            ->get(['segments.id', 'segments.name', 'segments.type', 'segments.member_count']);

        $out = [];

        foreach ($segments as $segment) {
            $enough = (int) $segment->member_count >= self::MIN_MEMBERS_FOR_STATS;
            $stats = DB::table('segment_members')->join('customer_metrics', 'customer_metrics.customer_id', '=', 'segment_members.customer_id')->where('segment_members.segment_id', $segment->id);

            $out[] = [
                'id' => (int) $segment->id,
                'name' => (string) $segment->name,
                'type' => (string) $segment->type,
                'members' => (int) $segment->member_count,
                'avg_recency_days' => $enough && ($r = (clone $stats)->avg('customer_metrics.recency_days')) !== null ? (int) round((float) $r) : null,
                'avg_monetary' => $enough && ($m = (clone $stats)->avg('customer_metrics.monetary')) !== null ? (int) round((float) $m) : null,
            ];
        }

        return $out;
    }
}
