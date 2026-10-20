<?php

declare(strict_types=1);

namespace App\Modules\Ai\Dto;

/**
 * One anomaly, or a run of consecutive days with the same metric and direction folded into one event. `observed`/`expected`/
 * `deviationPercent`/`robustZ` describe the PEAK day (largest |z|). Money is integer Toman; `refund_rate` and
 * `repeat_share` are percents with two decimals; severity is info|warning|critical; direction up|down.
 */
final readonly class AnomalyEvent
{
    public function __construct(
        public string $metric,
        public string $unit,
        public string $from,
        public string $to,
        public int $days,
        public string $peakDate,
        public int|float $observed,
        public int|float $expected,
        public float $deviationPercent,
        public float $robustZ,
        public string $severity,
        public string $direction,
        public ?string $knownEvent,
    ) {}
}
