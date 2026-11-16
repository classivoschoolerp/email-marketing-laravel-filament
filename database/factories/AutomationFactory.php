<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    protected $model = Automation::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->words(3, true),
            'status' => AutomationStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => AutomationStatus::Active]);
    }

    public function paused(): static
    {
        return $this->state(['status' => AutomationStatus::Paused]);
    }
}
