<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LandingPageVariant>
 */
class LandingPageVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'landing_page_id' => LandingPage::factory(),
            'customer_id' => fn (array $attributes): ?int => LandingPage::withoutTenancy()
                ->whereKey($attributes['landing_page_id'])->value('customer_id'),
            'name' => ucfirst(fake()->unique()->word()),
            'weight' => 50,
            'enabled' => true,
        ];
    }

    public function control(): static
    {
        return $this->state(['is_control' => true]);
    }

    public function weight(int $weight): static
    {
        return $this->state(['weight' => $weight]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
