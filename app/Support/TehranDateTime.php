<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** A stored instant (UTC) as a person reads it: the Jalali date from JalaliDate plus the wall-clock time in Asia/Tehran. */
final class TehranDateTime
{
    public static function format(DateTimeInterface $at): string
    {
        $tehran = CarbonImmutable::instance($at)->setTimezone('Asia/Tehran');

        return JalaliDate::format($tehran).' '.$tehran->format('H:i:s');
    }

    public static function formatOrNull(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : self::format($at);
    }
}
