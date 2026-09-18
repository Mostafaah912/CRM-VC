<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'phone_normalized' => '9891'.fake()->unique()->numerify('########'),
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => "{$first} {$last}",
            'first_seen_at' => now(),
        ];
    }
}
