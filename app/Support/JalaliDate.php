<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The single source of Gregorian <-> Jalali (Persian) calendar conversion
 * (CLAUDE.md §2). Timestamps are stored UTC; display always goes through
 * Asia/Tehran first. Uses the standard 33-year cycle algorithm shared by the
 * PL/pgSQL to_jalali()/to_jalali_month() functions added in P0-04, so both
 * sides of the stack agree on the same dates.
 */
final class JalaliDate
{
    private const MONTH_NAMES = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    private const G_DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    private const J_DAYS_IN_MONTH = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    /**
     * @return array{0: int, 1: int, 2: int} [jalaliYear, jalaliMonth, jalaliDay]
     */
    public static function toJalaliParts(DateTimeInterface $date): array
    {
        $tehran = CarbonImmutable::instance($date)->setTimezone('Asia/Tehran');

        return self::gregorianToJalali(
            (int) $tehran->format('Y'),
            (int) $tehran->format('n'),
            (int) $tehran->format('j'),
        );
    }

    public static function toGregorian(int $jalaliYear, int $jalaliMonth, int $jalaliDay): CarbonImmutable
    {
        [$gy, $gm, $gd] = self::jalaliToGregorian($jalaliYear, $jalaliMonth, $jalaliDay);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, 'Asia/Tehran');
    }

    public static function format(DateTimeInterface $date, string $glue = '/'): string
    {
        [$jy, $jm, $jd] = self::toJalaliParts($date);

        return sprintf('%04d%s%02d%s%02d', $jy, $glue, $jm, $glue, $jd);
    }

    /** Jalali month key used for cohort_month, matching the DB's to_jalali_month(). */
    public static function toJalaliMonth(DateTimeInterface $date): string
    {
        [$jy, $jm] = self::toJalaliParts($date);

        return sprintf('%04d-%02d', $jy, $jm);
    }

    public static function monthName(int $jalaliMonth): string
    {
        return self::MONTH_NAMES[$jalaliMonth]
            ?? throw new \InvalidArgumentException("Invalid Jalali month [{$jalaliMonth}].");
    }

    /**
     * @return array{0: int, 1: int, 2: int} [jalaliYear, jalaliMonth, jalaliDay]
     */
    private static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gy2 = $gy - 1600;
        $gm2 = $gm - 1;
        $gd2 = $gd - 1;

        $gDayNo = 365 * $gy2 + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400);

        for ($i = 0; $i < $gm2; $i++) {
            $gDayNo += self::G_DAYS_IN_MONTH[$i];
        }

        if ($gm2 > 1 && self::isGregorianLeap($gy)) {
            $gDayNo++;
        }

        $gDayNo += $gd2;

        $jDayNo = $gDayNo - 79;

        $jNp = intdiv($jDayNo, 12053);
        $jDayNo %= 12053;

        $jy = 979 + 33 * $jNp + 4 * intdiv($jDayNo, 1461);
        $jDayNo %= 1461;

        if ($jDayNo >= 366) {
            $jy += intdiv($jDayNo - 1, 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }

        $i = 0;
        while ($i < 11 && $jDayNo >= self::J_DAYS_IN_MONTH[$i]) {
            $jDayNo -= self::J_DAYS_IN_MONTH[$i];
            $i++;
        }

        return [$jy, $i + 1, $jDayNo + 1];
    }

    /**
     * @return array{0: int, 1: int, 2: int} [gregorianYear, gregorianMonth, gregorianDay]
     */
    private static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy2 = $jy - 979;
        $jm2 = $jm - 1;
        $jd2 = $jd - 1;

        $jDayNo = 365 * $jy2 + intdiv($jy2, 33) * 8 + intdiv(($jy2 % 33) + 3, 4);

        for ($i = 0; $i < $jm2; $i++) {
            $jDayNo += self::J_DAYS_IN_MONTH[$i];
        }

        $jDayNo += $jd2;

        $gDayNo = $jDayNo + 79;

        $gy = 1600 + 400 * intdiv($gDayNo, 146097);
        $gDayNo %= 146097;

        $leap = true;
        if ($gDayNo >= 36525) {
            $gDayNo--;
            $gy += 100 * intdiv($gDayNo, 36524);
            $gDayNo %= 36524;

            if ($gDayNo >= 365) {
                $gDayNo++;
            } else {
                $leap = false;
            }
        }

        $gy += 4 * intdiv($gDayNo, 1461);
        $gDayNo %= 1461;

        if ($gDayNo >= 366) {
            $leap = false;
            $gDayNo--;
            $gy += intdiv($gDayNo, 365);
            $gDayNo %= 365;
        }

        $i = 0;
        while ($gDayNo >= self::G_DAYS_IN_MONTH[$i] + ($i === 1 && $leap ? 1 : 0)) {
            $gDayNo -= self::G_DAYS_IN_MONTH[$i] + ($i === 1 && $leap ? 1 : 0);
            $i++;
        }

        return [$gy, $i + 1, $gDayNo + 1];
    }

    private static function isGregorianLeap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }
}
