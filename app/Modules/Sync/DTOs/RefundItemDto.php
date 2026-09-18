<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

/** One refunded line. Woo sends quantity and total negative; both are carried as positive magnitudes. */
final readonly class RefundItemDto
{
    public function __construct(
        public int $wooItemId,
        public ?int $wooProductId,
        public ?int $wooVariationId,
        public ?string $sku,
        public int $quantity,
        public int $amount,
    ) {}
}
