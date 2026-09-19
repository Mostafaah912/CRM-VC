<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

/** What the local orders table holds for a time window: how many orders (every status, not soft-deleted) and their realized revenue in integer Toman. */
final readonly class OrderWindowTotals
{
    public function __construct(
        public int $count,
        public int $realizedRevenue,
    ) {}
}
