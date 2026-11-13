<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        $slug = Str::lower(Str::random(8));

        return [
            'host' => $slug.'.brand.test',
            'name' => ucfirst(fake()->word()).' Brand',
            'active' => true,
            'is_default' => false,
            'registration_enabled' => true,
            'login_enabled' => true,
            'ssl_status' => Domain::SSL_UNKNOWN,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
