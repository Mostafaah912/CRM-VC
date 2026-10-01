<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use Carbon\CarbonImmutable;

/**
 * The dashboard's date range (PRD §18: "فیلتر بازه، مقایسه دوره") plus the immediately preceding
 * period of equal length, for period-over-period compare. Dates are `daily_metrics.date` values —
 * already Tehran-local calendar days (P6-02's own definition), so no further timezone conversion
 * happens here.
 */
final readonly class DashboardPeriod
{
    private function __construct(
        public string $from,
        public string $to,
        public string $previousFrom,
        public string $previousTo,
    ) {}

    public static function fromDates(CarbonImmutable $from, CarbonImmutable $to): self
    {
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        return new self($from->toDateString(), $to->toDateString(), $previousFrom->toDateString(), $previousTo->toDateString());
    }

    public static function lastDays(int $days, CarbonImmutable $asOf): self
    {
        $to = CarbonImmutable::parse($asOf->setTimezone('Asia/Tehran')->toDateString());

        return self::fromDates($to->subDays($days - 1), $to);
    }
}
