<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Support\Digits;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;

/**
 * A Jalali calendar day as a person types it — 1405/03/11 or 1405-03-11, ASCII or Persian digits, two-digit month and day — as the
 * instants that bound that day in Asia/Tehran: start() is its first instant, nextStart() the first instant of the day after
 * (the exclusive end). Anything that is not a real day of a plausible year (1300-1499: a Gregorian date typed by mistake is
 * not) is null. Conversion is JalaliDate's; a day it would silently roll over (1405/12/30 in a common year) is rejected.
 */
final class JalaliDay
{
    private const MIN_YEAR = 1300;

    private const MAX_YEAR = 1499;

    public static function start(string $input): ?CarbonImmutable
    {
        return self::tehranMidnight($input)?->utc();
    }

    public static function nextStart(string $input): ?CarbonImmutable
    {
        return self::tehranMidnight($input)?->addDay()->utc();
    }

    private static function tehranMidnight(string $input): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/D', trim(Digits::toAscii($input)), $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $tehran = JalaliDate::toGregorian($year, $month, $day);

        return JalaliDate::toJalaliParts($tehran) === [$year, $month, $day] ? $tehran : null;
    }
}
