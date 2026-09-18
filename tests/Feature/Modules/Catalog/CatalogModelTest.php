<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CostSource;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductCost;
use App\Modules\Catalog\Models\ProductVariation;

it('creates a product from the factory with typed enums', function () {
    $product = Product::factory()->create()->refresh();

    expect($product->type)->toBe(ProductType::Simple)
        ->and($product->status)->toBe(ProductStatus::Publish)
        ->and($product->woo_product_id)->toBeInt();
});

it('links a product to categories and back', function () {
    $product = Product::factory()->create();
    $women = ProductCategory::factory()->create(['name' => 'Women']);
    $dresses = ProductCategory::factory()->create(['name' => 'Dresses', 'parent_id' => $women->id]);

    $product->categories()->attach([$women->id, $dresses->id]);

    expect($product->categories)->toHaveCount(2)
        ->and($dresses->refresh()->parent->is($women))->toBeTrue()
        ->and($women->children)->toHaveCount(1)
        ->and($women->products->first()->is($product))->toBeTrue();
});

it('gives a variable product variations with JSON attributes and integer Toman prices', function () {
    $product = Product::factory()->create(['type' => ProductType::Variable]);

    $product->variations()->create([
        'woo_variation_id' => 7001,
        'sku' => 'HM-SHIRT-L-BLK',
        'attributes' => ['size' => 'L', 'color' => 'مشکی'],
        'price' => 403880,
        'status' => ProductStatus::Publish,
    ]);

    $variation = $product->variations()->firstOrFail();

    expect($variation->attributes)->toBe(['size' => 'L', 'color' => 'مشکی'])
        ->and($variation->price)->toBeInt()->toBe(403880)
        ->and($variation->product->is($product))->toBeTrue();
});

it('allows a variation with no price or SKU (never reject a synced record)', function () {
    $variation = ProductVariation::factory()->create(['price' => null, 'sku' => null]);

    expect($variation->refresh()->price)->toBeNull()->and($variation->sku)->toBeNull();
});

it('records a manual unit cost against a variation', function () {
    $variation = ProductVariation::factory()->create();

    $cost = $variation->costs()->create(['unit_cost' => 150000, 'effective_from' => '2026-01-01']);

    expect($cost->refresh()->source)->toBe(CostSource::Manual)
        ->and($cost->unit_cost)->toBe(150000)
        ->and($cost->effective_from->toDateString())->toBe('2026-01-01')
        ->and(ProductCost::query()->first()->variation->is($variation))->toBeTrue();
});

it('deletes a product together with its variations', function () {
    $product = Product::factory()->create();
    ProductVariation::factory()->create(['product_id' => $product->id]);

    $product->delete();

    expect(ProductVariation::query()->count())->toBe(0);
});

it('round-trips the `attributes` column despite Eloquent owning a property of that name', function () {
    $variation = ProductVariation::factory()->create(['attributes' => ['size' => 'M']]);

    $variation->update(['attributes' => ['size' => 'XL', 'color' => 'سفید']]);

    expect($variation->refresh()->attributes)->toBe(['size' => 'XL', 'color' => 'سفید'])
        ->and($variation->toArray()['attributes'])->toBe(['size' => 'XL', 'color' => 'سفید'])
        ->and(ProductVariation::query()->whereJsonContains('attributes->size', 'XL')->count())->toBe(1);
});
