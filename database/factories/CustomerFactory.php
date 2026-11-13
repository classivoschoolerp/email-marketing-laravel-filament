<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'company' => fake()->company(),
            'phone' => fake()->e164PhoneNumber(),
            'country' => fake()->countryCode(),
            'timezone' => 'UTC',
            'locale' => 'en',
            'status' => CustomerStatus::Active,
            'email_verified_at' => now(),
        ];
    }

    public function pendingConfirmation(): static
    {
        return $this->state([
            'status' => CustomerStatus::PendingConfirm,
            'email_verified_at' => null,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(['status' => CustomerStatus::Blocked]);
    }
}
