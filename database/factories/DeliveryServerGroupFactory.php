<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryServerGroupType;
use App\Models\Customer;
use App\Models\DeliveryServerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryServerGroup>
 */
class DeliveryServerGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => 'Pool '.fake()->unique()->word(),
            'type' => DeliveryServerGroupType::Pool,
            'enabled' => true,
            'is_default' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(['customer_id' => null]);
    }

    public function ipPool(): static
    {
        return $this->state(['type' => DeliveryServerGroupType::IpPool]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
