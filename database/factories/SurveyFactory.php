<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SurveyStatus;
use App\Models\Customer;
use App\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Survey>
 */
class SurveyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'display_name' => null,
            'description' => fake()->sentence(),
            'status' => SurveyStatus::Active,
            'allow_anonymous' => true,
            'one_response_per_subscriber' => true,
            'show_progress_bar' => true,
            'fields_per_step' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => SurveyStatus::Draft]);
    }

    public function closed(): static
    {
        return $this->state(['status' => SurveyStatus::Closed]);
    }

    public function identifiedOnly(): static
    {
        return $this->state(['allow_anonymous' => false]);
    }
}
