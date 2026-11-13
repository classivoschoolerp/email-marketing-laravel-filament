<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerGroup>
 */
class CustomerGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
