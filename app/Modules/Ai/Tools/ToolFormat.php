<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Support\JalaliDate;
use Carbon\CarbonImmutable;

/** Output conventions every tool shares: integer Toman, fixed-precision percents, ISO + Jalali dates, Latin digits. */
final class ToolFormat
{
    public static function int(mixed $value): int
    {
        return (int) round((float) $value);
    }

    public static function toman(mixed $value): int
    {
        return self::int($value);
    }

    /** Percent 0-100 with two fixed decimals; null when the denominator is zero. */
    public static function percent(int|float|string $part, int|float|string $whole): ?float
    {
        return (float) $whole === 0.0 ? null : round((float) $part / (float) $whole * 100, 2);
    }

    /** @return array{iso: string, jalali: string} */
    public static function date(string $iso): array
    {
        return ['iso' => $iso, 'jalali' => JalaliDate::format(CarbonImmutable::parse($iso, 'Asia/Tehran'))];
    }
}
