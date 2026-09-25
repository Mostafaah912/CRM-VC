<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\LifecycleStage;
use Carbon\CarbonImmutable;

/**
 * What the customer list is narrowed by. Every field is optional and they combine with AND. The first-seen range is
 * [firstSeenFrom, firstSeenBefore): inclusive start, EXCLUSIVE end — the start of the day after the last day asked for.
 * Only columns of `customers` itself: nothing here reaches customer_metrics.
 */
final readonly class CustomerListFilters
{
    public function __construct(
        public ?string $search = null,
        public ?CustomerStatus $status = null,
        public ?LifecycleStage $lifecycleStage = null,
        public ?string $province = null,
        public ?string $city = null,
        public ?bool $needsReview = null,
        public ?CarbonImmutable $firstSeenFrom = null,
        public ?CarbonImmutable $firstSeenBefore = null,
    ) {}
}
