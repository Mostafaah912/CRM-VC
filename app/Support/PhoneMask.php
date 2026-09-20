<?php

declare(strict_types=1);

namespace App\Support;

/** A normalized phone as a viewer without customers.view_full_phone sees it: the last four digits stay, every other digit is `*`, the length is kept. */
final class PhoneMask
{
    private const VISIBLE = 4;

    public static function mask(string $phone): string
    {
        $length = strlen($phone);

        return $length <= self::VISIBLE
            ? str_repeat('*', $length)
            : str_repeat('*', $length - self::VISIBLE).substr($phone, -self::VISIBLE);
    }
}
