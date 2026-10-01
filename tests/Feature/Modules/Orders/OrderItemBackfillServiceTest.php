<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderItemBackfillService;

/*
| P6 decision (ARCHITECTURE.md): a local, Woo-free re-resolution of order_items already stored with NULL
| product_id/variation_id. OrderService::resolve()'s steps 1/2 need the raw Woo variation/product id,
| which order_items never persists — only its two SKU-only steps (variation-by-sku, then product-by-sku)
| are reconstructable from a stored row, so that is all this backfill can ever do (documented limitation,
| docs/architecture/sprint-6.md). Idempotent: only rows still NULL on both ids are touched.
*/

function unresolvedItem(array $overrides = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => Order::factory()->create()->id,
        'woo_item_id' => fake()->unique()->numberBetween(1, 9_000_000),
        'product_id' => null,
        'variation_id' => null,
        'sku' => null,
        'name_snapshot' => 'Test item',
        'qty' => 1,
        'unit_price' => 100,
        'line_subtotal' => 100,
        'line_total' => 100,
    ], $overrides));
}

it('resolves an unresolved item to a variation by its stored sku', function () {
    $variation = ProductVariation::factory()->create(['sku' => 'SYN-SHIRT-S']);
    $item = unresolvedItem(['sku' => 'SYN-SHIRT-S']);

    $result = app(OrderItemBackfillService::class)->resolveUnresolved();

    expect([$result->resolvedAsVariation, $result->resolvedAsProduct, $result->stillUnresolved])->toBe([1, 0, 0]);
    $item->refresh();
    expect($item->product_id)->toBe($variation->product_id)->and($item->variation_id)->toBe($variation->id);
});

it('resolves an unresolved item to a simple product by its own sku when no variation matches', function () {
    $product = Product::factory()->create(['sku' => 'SYN-TEE-001']);
    $item = unresolvedItem(['sku' => 'SYN-TEE-001']);

    $result = app(OrderItemBackfillService::class)->resolveUnresolved();

    expect([$result->resolvedAsVariation, $result->resolvedAsProduct, $result->stillUnresolved])->toBe([0, 1, 0]);
    $item->refresh();
    expect($item->product_id)->toBe($product->id)->and($item->variation_id)->toBeNull();
});

it('counts a sku that matches nothing as still unresolved, and leaves it null', function () {
    $item = unresolvedItem(['sku' => 'NO-SUCH-SKU']);

    $result = app(OrderItemBackfillService::class)->resolveUnresolved();

    expect([$result->resolvedAsVariation, $result->resolvedAsProduct, $result->stillUnresolved])->toBe([0, 0, 1]);
    $item->refresh();
    expect($item->product_id)->toBeNull()->and($item->variation_id)->toBeNull();
});

it('never touches a row that has no stored sku at all (the 5,798-row population this task must leave alone)', function () {
    $item = unresolvedItem(['sku' => null]);

    $result = app(OrderItemBackfillService::class)->resolveUnresolved();

    expect([$result->resolvedAsVariation, $result->resolvedAsProduct, $result->stillUnresolved])->toBe([0, 0, 0]);
    expect($item->refresh()->sku)->toBeNull();
});

it('never touches an already-resolved row, even if its sku would also match something else now', function () {
    $product = Product::factory()->create(['id' => 999999, 'sku' => 'SYN-TEE-001']);
    $resolved = unresolvedItem(['sku' => 'SYN-TEE-001', 'product_id' => $product->id]);

    $result = app(OrderItemBackfillService::class)->resolveUnresolved();

    expect([$result->resolvedAsVariation, $result->resolvedAsProduct, $result->stillUnresolved])->toBe([0, 0, 0]);
    expect($resolved->refresh()->product_id)->toBe($product->id);
});

it('is idempotent: running it twice resolves nothing new the second time', function () {
    ProductVariation::factory()->create(['sku' => 'SYN-SHIRT-S']);
    unresolvedItem(['sku' => 'SYN-SHIRT-S']);
    $service = app(OrderItemBackfillService::class);

    $first = $service->resolveUnresolved();
    $second = $service->resolveUnresolved();

    expect($first->resolvedAsVariation)->toBe(1)
        ->and([$second->resolvedAsVariation, $second->resolvedAsProduct, $second->stillUnresolved])->toBe([0, 0, 0]);
});
