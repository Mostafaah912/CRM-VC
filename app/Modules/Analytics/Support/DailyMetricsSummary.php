<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/** What DailyMetricsService::rebuild() (P6-02) returns. */
final readonly class DailyMetricsSummary
{
    public function __construct(
        public int $daysProcessed,
        public int $elapsedMs,
    ) {}
}
