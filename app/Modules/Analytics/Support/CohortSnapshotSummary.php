<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/** What CohortSnapshotService::rebuild() (P6-03) returns. */
final readonly class CohortSnapshotSummary
{
    public function __construct(
        public int $rowsWritten,
        public int $elapsedMs,
    ) {}
}
