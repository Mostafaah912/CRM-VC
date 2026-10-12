<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/** Validates a non-negative decimal string for bcmath. Anything else is the safe fallback — never a float, never a guess. */
final class Decimal
{
    /** @return numeric-string */
    public static function of(mixed $value, string $fallback = '0'): string
    {
        $string = is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';

        if (is_numeric($string) && preg_match('/^\d+(\.\d+)?$/', $string) === 1) {
            return $string;
        }

        return is_numeric($fallback) ? $fallback : '0';
    }

    public static function isValid(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && preg_match('/^\d+(\.\d+)?$/', (string) $value) === 1;
    }
}
