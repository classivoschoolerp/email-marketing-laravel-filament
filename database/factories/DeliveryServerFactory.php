<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryServerStatus;
use App\Enums\QuotaExceededAction;
use App\Enums\ServerProtocol;
use App\Models\Customer;
use App\Models\DeliveryServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryServer>
 */
class DeliveryServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => 'smtp',
            'name' => fake()->unique()->words(3, true),
            'hostname' => 'smtp.'.fake()->domainName(),
            'port' => 587,
            'protocol' => ServerProtocol::Tls,
            'username' => fake()->userName(),
            'password' => 'secret-password',
            'from_email' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'reply_to_email' => fake()->safeEmail(),
            'probability' => 100,
            'quota_exceeded_action' => QuotaExceededAction::Pause,
            'status' => DeliveryServerStatus::Active,
        ];
    }

    public function system(): static
    {
        return $this->state(['customer_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => DeliveryServerStatus::Inactive]);
    }

    public function locked(): static
    {
        return $this->state(['locked' => true]);
    }

    public function ofType(string $type): static
    {
        return $this->state(['type' => $type]);
    }
}
