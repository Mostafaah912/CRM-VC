<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\InvalidPhoneException;

/**
 * The single source of phone normalization for the whole app (CLAUDE.md §2).
 * Every phone value — sync, forms, imports — must go through normalize().
 */
final class PhoneNormalizer
{
    /** U+200E LEFT-TO-RIGHT MARK, U+200F RIGHT-TO-LEFT MARK. */
    private const DIRECTION_MARKS = ["\u{200E}", "\u{200F}"];

    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const ASCII_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /**
     * Normalize any raw phone input into the canonical 989XXXXXXXXX format.
     *
     * @throws InvalidPhoneException when the value cannot be resolved to a valid
     *                               Iranian mobile number. Never returns garbage.
     */
    public static function normalize(?string $raw): string
    {
        $original = $raw ?? '';

        $clean = str_replace(self::DIRECTION_MARKS, '', $original);
        $clean = str_replace(self::PERSIAN_DIGITS, self::ASCII_DIGITS, $clean);
        $clean = str_replace(self::ARABIC_INDIC_DIGITS, self::ASCII_DIGITS, $clean);

        $digits = preg_replace('/\D+/', '', $clean) ?? '';

        if ($digits === '') {
            throw InvalidPhoneException::empty();
        }

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $national = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $national = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $national = $digits;
        } else {
            throw InvalidPhoneException::tooShort($original);
        }

        if (strlen($national) !== 10 || $national[0] !== '9') {
            throw InvalidPhoneException::notMobile($original);
        }

        return '98'.$national;
    }
}
