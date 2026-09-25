<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

/** What SegmentService::rebuildAll() (P5-08) returns — one segment's failure never stops the rest. */
final readonly class SegmentRebuildSummary
{
    /**
     * @param  list<int>  $succeededSegmentIds
     * @param  array<int, string>  $failedSegmentIds  segment id => the exception message that failed it
     */
    public function __construct(
        public array $succeededSegmentIds,
        public array $failedSegmentIds,
        public int $elapsedMs,
    ) {}
}
