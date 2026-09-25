<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use Carbon\CarbonImmutable;

/**
 * What the order list is narrowed by. Every field is optional and they combine with AND. The date range is
 * [orderedFrom, orderedBefore): inclusive start, EXCLUSIVE end — the start of the day after the last day asked for (JalaliDay,
 * same as P3-01). `status` is a raw Woo slug string, not an enum: CLAUDE.md §3/PRD forbid an order-status enum, since the set of
 * statuses is store data, not app code — it is validated against the statuses actually seen in `orders.status` instead.
 */
final readonly class OrderListFilters
{
    public function __construct(
        public ?int $wooOrderId = null,
        public ?string $status = null,
        public ?bool $isRealized = null,
        public ?bool $needsPhoneReview = null,
        public ?CarbonImmutable $orderedFrom = null,
        public ?CarbonImmutable $orderedBefore = null,
    ) {}
}
