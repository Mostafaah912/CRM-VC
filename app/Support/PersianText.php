<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A display/storage value (city, province — not a comparison key, see
 * `App\Modules\Customers\Services\PersonNameNormalizer` for that) cleaned of the two Arabic letter
 * shapes that are typography, not identity, in Persian text (CLAUDE.md §2): Arabic yeh/alef maksura
 * and kaf become Persian yeh and keh. Internal spaces, case and every other character are untouched.
 */
final class PersianText
{
    /** Arabic yeh, alef maksura and kaf -> Persian yeh and keh. */
    private const LETTER_SHAPES = [
        "\u{064A}" => "\u{06CC}",
        "\u{0649}" => "\u{06CC}",
        "\u{0643}" => "\u{06A9}",
    ];

    public static function fixLetterShapes(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim(strtr($value, self::LETTER_SHAPES));
    }
}
