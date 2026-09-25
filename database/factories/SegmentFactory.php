<?php

namespace Database\Factories;

use App\Modules\Segments\Enums\SegmentType;
use App\Modules\Segments\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Segment> */
class SegmentFactory extends Factory
{
    protected $model = Segment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => SegmentType::Dynamic,
            'rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1],
            'rule_version' => 1,
            'is_active' => true,
            'is_system' => false,
        ];
    }

    public function static(): static
    {
        return $this->state(fn (): array => ['type' => SegmentType::Static, 'rule' => null]);
    }

    public function manual(): static
    {
        return $this->state(fn (): array => ['type' => SegmentType::Manual, 'rule' => null]);
    }
}
