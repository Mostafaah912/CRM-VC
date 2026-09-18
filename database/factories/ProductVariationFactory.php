<?php

namespace Database\Factories;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariation> */
class ProductVariationFactory extends Factory
{
    protected $model = ProductVariation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'woo_variation_id' => fake()->unique()->numberBetween(1, 9_000_000),
            'sku' => 'HM-'.fake()->unique()->numerify('######'),
            'attributes' => [],
            'price' => fake()->numberBetween(100_000, 5_000_000),
            'status' => ProductStatus::Publish,
        ];
    }
}
