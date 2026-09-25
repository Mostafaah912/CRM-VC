<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

/**
 * One order line. Product/variation ids are null when Woo sends 0 or the product no longer exists —
 * the line is kept with its sku and name snapshot (PRD §10: never reject an order over mapping).
 * Amounts are int Toman. `unitPrice` is floor(line total / quantity) — 0 for a zero quantity — computed by the mapper in
 * integer arithmetic; it is never Woo's own `price`, which is a derived float.
 */
final readonly class OrderItemDto
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
