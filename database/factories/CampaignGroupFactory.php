<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CampaignGroup;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignGroup>
 */
class CampaignGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
        ];
    }
}
