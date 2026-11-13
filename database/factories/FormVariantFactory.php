<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVariant>
 */
class FormVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'customer_id' => fn (array $attributes): ?int => Form::withoutTenancy()
                ->whereKey($attributes['form_id'])->value('customer_id'),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'weight' => 50,
            'is_control' => false,
            'is_winner' => false,
            'enabled' => true,
        ];
    }

    public function control(): static
    {
        return $this->state(['is_control' => true]);
    }

    public function winner(): static
    {
        return $this->state(['is_winner' => true]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }

    public function weight(int $weight): static
    {
        return $this->state(['weight' => $weight]);
    }
}
