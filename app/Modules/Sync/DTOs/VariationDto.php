<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

/**
 * A Woo product variation. Woo's variation payload does not name its parent, so `wooProductId`
 * comes from the endpoint the caller read it from. `attributes` maps attribute name => chosen option.
 * `droppedPrice` is the raw price Woo sent when it was not a whole-Toman amount (then `price` is null): carried so the SYNC SERVICE
 * can log it — mappers never log (P8 decision).
 */
final readonly class VariationDto
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public int $wooVariationId,
        public int $wooProductId,
        public ?string $sku,
        public ?int $price,
        public string $status,
        public array $attributes,
        public ?string $droppedPrice = null,
    ) {}
}
