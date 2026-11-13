<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Customer;
use App\Models\SendingDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SendingDomain>
 *
 * Uses a static test keypair instead of a fresh openssl generation per row —
 * key generation is exercised through GenerateDkimKeypair tests, factories
 * only need shaped data.
 */
class SendingDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->unique()->domainName(),
            'dkim_selector' => 'default',
            'dkim_private_key' => "-----BEGIN PRIVATE KEY-----\nMIIfactoryfakekey\n-----END PRIVATE KEY-----",
            'dkim_public_key' => "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAfactorypublickey\n-----END PUBLIC KEY-----",
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
            'status' => DomainStatus::Verified,
            'dkim_verified' => true,
            'spf_verified' => true,
            'dmarc_verified' => true,
            'last_checked_at' => now(),
        ]);
    }
}
