<?php

declare(strict_types=1);

namespace App\Modules\Sync\DTOs;

/**
 * A Woo product variation. Woo's variation payload does not name its parent, so `wooProductId`
 * comes from the endpoint the caller read it from. `attributes` maps attribute name => chosen option.
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
    ) {}
}
