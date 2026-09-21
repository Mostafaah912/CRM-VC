<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** An order (with its items) was written by sync (PRD §07), same pattern as Catalog\Events\ProductSynced. Ids only. */
final class OrderSynced
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly ?int $customerId,
    ) {}
}
