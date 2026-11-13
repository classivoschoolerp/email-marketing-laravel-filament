<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\TemplateCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateCategory>
 */
class TemplateCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0,
        ];
    }

    public function system(): static
    {
        return $this->state(['customer_id' => null]);
    }
}
