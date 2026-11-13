<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InfraServerStatus;
use App\Enums\MailboxEncryption;
use App\Enums\MailboxProtocol;
use App\Models\Customer;
use App\Models\EmailBoxMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailBoxMonitor>
 */
class EmailBoxMonitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => 'Monitor '.fake()->unique()->word(),
            'hostname' => 'pop.'.fake()->domainName(),
            'port' => 995,
            'protocol' => MailboxProtocol::Pop3,
            'username' => fake()->userName(),
            'password' => 'monitor-secret',
            'encryption' => MailboxEncryption::Ssl,
            'validate_cert' => true,
            'delete_after_processing' => true,
            'max_per_batch' => 200,
            'search_charset' => 'UTF-8',
            'status' => InfraServerStatus::Active,
        ];
    }

    public function system(): static
    {
        return $this->state(['customer_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => InfraServerStatus::Inactive]);
    }

    public function locked(): static
    {
        return $this->state(['locked' => true]);
    }
}
