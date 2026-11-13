<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SpintaxSnippet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpintaxSnippet>
 */
class SpintaxSnippetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'content' => '{Hi|Hello|Hey} {there|friend}',
        ];
    }
}
