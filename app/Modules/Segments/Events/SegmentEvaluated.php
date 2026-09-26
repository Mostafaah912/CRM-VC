<?php

declare(strict_types=1);

namespace App\Modules\Segments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A dynamic segment finished a full evaluation run (PRD §17). Aggregate counts only — no customer data. */
final class SegmentEvaluated
{
    use Dispatchable;

    public function __construct(
        public readonly int $segmentId,
        public readonly int $memberCount,
        public readonly int $enteredCount,
        public readonly int $leftCount,
        public readonly int $elapsedMs,
    ) {}
}
