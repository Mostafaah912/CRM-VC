<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Modules\Sync\Exceptions\ReconciliationMonthException;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;

/**
 * Jalali months for reconciliation. A month is "YYYY-MM" (Jalali, ASCII digits). Its window is half-open in UTC:
 * [first day 00:00 Asia/Tehran, first day of the next month 00:00 Asia/Tehran), converted with JalaliDate. The FIRST month
 * is the month of woo.sync_epoch (Mehr 1403) and the last COMPLETE month is the month before the current Jalali month in
 * Tehran; only months in that range can be reconciled — the running month is still changing, so it is never judged.
 * "YYYY-MM" strings sort chronologically, which is what the range checks rely on.
 */
final class ReconciliationMonths
{
    private const FORMAT = '/^(\d{4})-(0[1-9]|1[0-2])$/D';

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC start (inclusive) and end (exclusive)
     */
    public function window(string $month): array
    {
        [$year, $number] = $this->parse($month);
        [$nextYear, $nextNumber] = $number === 12 ? [$year + 1, 1] : [$year, $number + 1];

        return [
            JalaliDate::toGregorian($year, $number, 1)->utc(),
            JalaliDate::toGregorian($nextYear, $nextNumber, 1)->utc(),
        ];
    }

    /**
     * @return array{0: string, 1: string} the first and the last day of the month, as Tehran dates (Y-m-d)
     */
    public function dates(string $month): array
    {
        [$start, $end] = $this->window($month);

        return [$start->setTimezone('Asia/Tehran')->toDateString(), $end->setTimezone('Asia/Tehran')->subDay()->toDateString()];
    }

    /** The first month to reconcile: the Jalali month of woo.sync_epoch. */
    public function first(): string
    {
        return JalaliDate::toJalaliMonth(CarbonImmutable::parse((string) config('woo.sync_epoch')));
    }

    /** The month before the current Jalali month in Tehran. */
    public function lastComplete(): string
    {
        return $this->previous(JalaliDate::toJalaliMonth(CarbonImmutable::now('Asia/Tehran')));
    }

    /**
     * Every month from the first to the last complete one, oldest first; empty while the first month is still running.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $months = [];
        $last = $this->lastComplete();

        for ($month = $this->first(); $month <= $last; $month = $this->next($month)) {
            $months[] = $month;
        }

        return $months;
    }

    /** @throws ReconciliationMonthException */
    public function assertReconcilable(string $month): void
    {
        $this->parse($month);

        if ($month < $this->first()) {
            throw ReconciliationMonthException::beforeFirstMonth();
        }

        if ($month > $this->lastComplete()) {
            throw ReconciliationMonthException::notComplete();
        }
    }

    /**
     * @return array{0: int, 1: int} Jalali year and month
     *
     * @throws ReconciliationMonthException
     */
    private function parse(string $month): array
    {
        if (preg_match(self::FORMAT, $month, $parts) !== 1) {
            throw ReconciliationMonthException::invalidFormat();
        }

        return [(int) $parts[1], (int) $parts[2]];
    }

    private function next(string $month): string
    {
        [$year, $number] = $this->parse($month);

        return $number === 12 ? sprintf('%04d-01', $year + 1) : sprintf('%04d-%02d', $year, $number + 1);
    }

    private function previous(string $month): string
    {
        [$year, $number] = $this->parse($month);

        return $number === 1 ? sprintf('%04d-12', $year - 1) : sprintf('%04d-%02d', $year, $number - 1);
    }
}
