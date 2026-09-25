<?php

declare(strict_types=1);

namespace App\Modules\Segments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A customer matched a segment's rule on the segment's latest evaluation. Ids only — no name, no phone. Recorded only, no listener in Phase 1. */
final class CustomerEnteredSegment
{
    use Dispatchable;

    public function __construct(
        public readonly int $segmentId,
        public readonly int $customerId,
    ) {}
}
