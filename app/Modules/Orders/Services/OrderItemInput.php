<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

/** One Woo order line as Orders' public service accepts it. Woo ids are null for "none" (Woo's 0); amounts are int Toman. */
final readonly class OrderItemInput
{
    public function __construct(
        public int $wooItemId,
        public ?int $wooProductId,
        public ?int $wooVariationId,
        public ?string $sku,
        public string $name,
        public int $quantity,
        public int $unitPrice,
        public int $lineSubtotal,
        public int $lineTotal,
    ) {}
}
