<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'woo_order_id' => fake()->unique()->numberBetween(1, 9_000_000),
            'customer_id' => Customer::factory(),
            'status' => 'processing',
            'ordered_at' => now(),
        ];
    }
}
