<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BounceType;
use App\Models\BounceRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BounceRule>
 */
class BounceRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Rule '.fake()->unique()->word(),
            'pattern' => 'user\s+unknown',
            'type' => BounceType::Hard,
            'enabled' => true,
            'sort_order' => 0,
        ];
    }
}
