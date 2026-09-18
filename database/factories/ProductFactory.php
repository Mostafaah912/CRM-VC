<?php

namespace Database\Factories;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'woo_product_id' => fake()->unique()->numberBetween(1, 9_000_000),
            'name' => fake()->words(3, true),
            'type' => ProductType::Simple,
            'status' => ProductStatus::Publish,
        ];
    }
}
