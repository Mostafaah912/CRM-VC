<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

/**
 * The canonical form used to decide whether two last names are "the same" (PRD §08 flow, step 5).
 * PRD says a conflict needs the name to differ with "similarity low" but defines no algorithm or
 * threshold, so nothing fuzzy is invented: names are compatible only when their canonical forms are
 * identical. The canonical form drops what is typography rather than identity — Arabic vs Persian
 * letter shapes, ZWNJ and every kind of space, direction marks, tatweel, diacritics, Latin case.
 * Any other difference is a real one and goes to human review, which never merges or blocks anything.
 */
final class PersonNameNormalizer
{
    /** Arabic yeh, alef maksura and kaf -> Persian yeh and keh. */
    private const LETTER_SHAPES = [
        "\u{064A}" => "\u{06CC}",
        "\u{0649}" => "\u{06CC}",
        "\u{0643}" => "\u{06A9}",
    ];

    /** Diacritics, tatweel, zero-width and direction marks (incl. ZWNJ U+200C), BOM, and all whitespace. */
    private const IGNORED = '/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{00A0}\s]+/u';

    public function normalize(string $name): string
    {
        return mb_strtolower(preg_replace(self::IGNORED, '', strtr($name, self::LETTER_SHAPES)) ?? '');
    }
}
