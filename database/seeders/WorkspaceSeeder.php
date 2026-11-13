<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Commands\DemoSeedCommand;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The one workspace (customer panel account) of a local or test install.
 * DatabaseSeeder runs WithoutModelEvents, so the uid is set here.
 * The optional DemoDataSeeder fills this same account with data.
 */
class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        Customer::query()->firstOrCreate(
            ['email' => DemoSeedCommand::DEMO_EMAIL],
            [
                'uid' => (string) Str::uuid(),
                'first_name' => 'Demo',
                'last_name' => 'Account',
                'password' => 'demo-password-1234',
                'company' => 'Demo Company',
                'country' => 'US',
                'timezone' => 'UTC',
                'locale' => 'en',
                'status' => CustomerStatus::Active,
                'email_verified_at' => now(),
            ],
        );
    }
}
