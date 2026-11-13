<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'action' => 'list.created',
            'actor_name' => $this->faker->name(),
            'description' => null,
            'ip' => $this->faker->ipv4(),
            'created_at' => now(),
        ];
    }
}
