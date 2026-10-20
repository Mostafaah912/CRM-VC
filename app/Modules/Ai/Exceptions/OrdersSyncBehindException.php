<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/**
 * The orders cursor is more than ai.generation.max_orders_sync_behind_hours before the END of the Tehran day being analysed: orders of that
 * day are missing, so generating would pay for a confidently wrong answer about an incomplete day (the same idea as StaleDataException).
 */
final class OrdersSyncBehindException extends RuntimeException
{
    public function __construct(public readonly float $behindHours, public readonly float $maxHours)
    {
        parent::__construct("The orders sync is {$behindHours} h behind the end of the day being analysed (limit {$maxHours} h); generation was refused.");
    }
}
