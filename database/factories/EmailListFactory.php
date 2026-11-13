<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\EmailList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailList>
 */
class EmailListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(),
            'opt_in' => 'single',
            'opt_out' => 'single',
            'from_name' => fake()->name(),
            'from_email' => fake()->safeEmail(),
        ];
    }

    public function doubleOptIn(): static
    {
        return $this->state(['opt_in' => 'double']);
    }

    public function hidden(): static
    {
        return $this->state(['visible' => false]);
    }
}
