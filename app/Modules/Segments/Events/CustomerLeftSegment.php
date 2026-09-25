<?php

declare(strict_types=1);

namespace App\Modules\Segments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A customer no longer matches a segment's rule as of its latest evaluation. Ids only. Recorded only, no listener in Phase 1. */
final class CustomerLeftSegment
{
    use Dispatchable;

    public function __construct(
        public readonly int $segmentId,
        public readonly int $customerId,
    ) {}
}
