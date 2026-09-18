<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductCategory> */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'woo_category_id' => fake()->unique()->numberBetween(1, 9_000_000),
            'name' => fake()->word(),
        ];
    }
}
