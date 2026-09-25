<?php

declare(strict_types=1);

namespace App\Modules\Segments\Support;

/** What SegmentService::evaluate() returns — the numbers the Segment pages (P5-06) show, nothing else. */
final readonly class SegmentEvaluationResult
{
    public function __construct(
        public int $segmentId,
        public int $memberCount,
        public int $enteredCount,
        public int $leftCount,
        public int $elapsedMs,
    ) {}
}
