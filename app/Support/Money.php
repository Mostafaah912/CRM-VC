<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\InvalidMoneyException;

/**
 * Money is always an int Toman — never a float (CLAUDE.md §2). This class is
 * the single place that formats an int Toman amount for display and parses
 * user/import input back into an int Toman amount.
 */
final class Money
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const ASCII_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private const PERSIAN_THOUSANDS_SEPARATOR = '٬';

    private const SUFFIX = 'تومان';

    public static function format(int $amountToman, bool $withSuffix = true): string
    {
        $grouped = number_format($amountToman, 0, '.', self::PERSIAN_THOUSANDS_SEPARATOR);
        $persian = self::toPersianDigits($grouped);

        return $withSuffix ? "{$persian} ".self::SUFFIX : $persian;
    }

    public static function toPersianDigits(int|string $value): string
    {
        return str_replace(self::ASCII_DIGITS, self::PERSIAN_DIGITS, (string) $value);
    }

    /**
     * @throws InvalidMoneyException when the value is not a clean integer amount.
     */
    public static function parseToman(string $raw): int
    {
        $original = $raw;

        $clean = str_replace(self::PERSIAN_DIGITS, self::ASCII_DIGITS, $raw);
        $clean = str_replace(self::ARABIC_INDIC_DIGITS, self::ASCII_DIGITS, $clean);
        $clean = str_replace(self::SUFFIX, '', $clean);
        $clean = str_replace([self::PERSIAN_THOUSANDS_SEPARATOR, ',', ' ', "\u{200C}"], '', $clean);
        $clean = trim($clean);

        if ($clean === '' || ! ctype_digit($clean)) {
            throw InvalidMoneyException::forValue($original);
        }

        return (int) $clean;
    }
}
