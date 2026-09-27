<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Support\OrderItemBackfillResult;

/**
 * Local, Woo-free re-resolution of order_items already stored with NULL product_id/variation_id (P6
 * decision, ARCHITECTURE.md — no such mechanism existed before this). OrderService::resolve()'s steps 1
 * and 2 need the raw Woo variation/product id, which order_items never persists (docs/architecture/
 * sprint-6.md) — only its two SKU-only steps are reconstructable from a stored row: variation-by-sku,
 * then, if that fails, a simple product's own sku. No Woo call is made. Idempotent: the query only ever
 * selects rows still NULL on both ids, so a repeat run changes nothing already resolved, and a row with
 * no stored sku is never selected at all (there is nothing to re-resolve it with).
 */
final class OrderItemBackfillService
{
    private const CHUNK = 500;

    public function __construct(private readonly CatalogService $catalog) {}

    public function resolveUnresolved(): OrderItemBackfillResult
    {
        $resolvedAsVariation = 0;
        $resolvedAsProduct = 0;
        $stillUnresolved = 0;

        OrderItem::query()
            ->whereNull('product_id')
            ->whereNull('variation_id')
            ->whereNotNull('sku')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($items) use (&$resolvedAsVariation, &$resolvedAsProduct, &$stillUnresolved): void {
                foreach ($items as $item) {
                    $resolved = $this->catalog->resolveVariationBySku($item->sku) ?? $this->catalog->resolveProductBySku($item->sku);

                    if ($resolved === null) {
                        $stillUnresolved++;

                        continue;
                    }

                    $item->forceFill(['product_id' => $resolved->productId, 'variation_id' => $resolved->variationId])->save();

                    $resolved->variationId === null ? $resolvedAsProduct++ : $resolvedAsVariation++;
                }
            });

        return new OrderItemBackfillResult($resolvedAsVariation, $resolvedAsProduct, $stillUnresolved);
    }
}
