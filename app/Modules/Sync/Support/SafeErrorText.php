<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use Throwable;

/**
 * The only way an exception becomes text in sync_jobs.error or sync_logs (CLAUDE.md §6: never log full phone numbers).
 * Exception messages are written by anyone — some carry a customer's phone — so every run of 7 or more digits, in any
 * script and with single separators (spaces, dots, dashes, parentheses) between them, is replaced. Fail-safe by design:
 * a date such as 2026-06-01 is redacted too, which costs a little detail and never leaks a number.
 */
final class SafeErrorText
{
    private const NUMBER_LIKE = '/\+?\p{Nd}(?:[\s().-]?\p{Nd}){6,}/u';

    public static function from(Throwable $e, int $limit = 1000): string
    {
        return self::cap(class_basename($e).': '.self::scrub($e->getMessage()), $limit);
    }

    public static function scrub(string $text): string
    {
        $clean = preg_replace(self::NUMBER_LIKE, '[number]', mb_scrub($text));

        return $clean ?? '[unreadable]';
    }

    public static function cap(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, max(0, $limit - 1)).'…';
    }
}
