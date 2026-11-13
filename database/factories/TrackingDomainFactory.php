<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Customer;
use App\Models\TrackingDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackingDomain>
 */
class TrackingDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => 'track.'.fake()->unique()->domainName(),
            'verified' => false,
            'ssl_status' => 'none',
            'status' => DomainStatus::Pending,
        ];
    }

    public function system(): static
    {
        return $this->state(['customer_id' => null]);
    }

    public function verified(): static
    {
        return $this->state([
            'verified' => true,
            'status' => DomainStatus::Verified,
            'last_checked_at' => now(),
        ]);
    }
}
