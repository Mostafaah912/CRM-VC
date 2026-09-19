<?php

declare(strict_types=1);

namespace App\Modules\Sync\Exceptions;

use InvalidArgumentException;

/** A month that cannot be reconciled: not a Jalali YYYY-MM, before the first month, or not complete yet. The message is static — it never echoes what was typed. */
final class ReconciliationMonthException extends InvalidArgumentException
{
    public static function invalidFormat(): self
    {
        return new self('The month must be a Jalali YYYY-MM.');
    }

    public static function beforeFirstMonth(): self
    {
        return new self('That month is before the first reconciliation month.');
    }

    public static function notComplete(): self
    {
        return new self('That month is not complete yet.');
    }
}
