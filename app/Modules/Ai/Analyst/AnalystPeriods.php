<?php

declare(strict_types=1);

namespace App\Modules\Ai\Analyst;

use App\Modules\Ai\Dto\AnalystPeriod;
use App\Modules\Ai\Enums\AiInsightType;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Which complete period an insight is about — computed from the Asia/Tehran CALENDAR, never from the hour the job runs:
 *  - daily_brief  = yesterday (a full Tehran day);
 *  - weekly_review = the last COMPLETE Jalali week, Saturday through Friday (the week a Saturday-morning run is reviewing just ended
 *    the night before; run on any other weekday it still means the last finished Saturday-to-Friday week).
 * `now` is injectable so the boundaries (midnight, week edge, Jalali new year) are testable.
 */
final class AnalystPeriods
{
    public function for(AiInsightType $type, ?CarbonImmutable $now = null): AnalystPeriod
    {
        return match ($type) {
            AiInsightType::DailyBrief => $this->daily($now),
            AiInsightType::WeeklyReview => $this->weekly($now),
            default => throw new InvalidArgumentException("No scheduled period for {$type->value}."),
        };
    }

    public function daily(?CarbonImmutable $now = null): AnalystPeriod
    {
        return $this->day($this->tehran($now)->startOfDay()->subDay());
    }

    /** A specific Tehran day (ISO), for manual runs. */
    public function day(CarbonImmutable $day): AnalystPeriod
    {
        $d = $day->setTimezone('Asia/Tehran')->startOfDay();

        return new AnalystPeriod($d->toDateString(), $d->toDateString(), 'day:'.$d->toDateString(), JalaliDate::format($d), JalaliDate::format($d));
    }

    public function weekly(?CarbonImmutable $now = null): AnalystPeriod
    {
        $thisWeekStart = $this->tehran($now)->startOfDay()->startOfWeek(CarbonImmutable::SATURDAY);
        $start = $thisWeekStart->subDays(7);
        $end = $thisWeekStart->subDay();   // the Friday

        return new AnalystPeriod($start->toDateString(), $end->toDateString(), 'week:'.$start->toDateString().'..'.$end->toDateString(), JalaliDate::format($start), JalaliDate::format($end));
    }

    private function tehran(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone('Asia/Tehran');
    }
}
