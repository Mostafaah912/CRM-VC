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
            'needs_phone_review' => false,
            'status' => 'processing',
            'ordered_at' => now(),
        ];
    }

    /** An order Woo gave no usable phone: no customer, flagged for review. */
    public function phoneless(): static
    {
        return $this->state(fn (): array => ['customer_id' => null, 'needs_phone_review' => true]);
    }
}
