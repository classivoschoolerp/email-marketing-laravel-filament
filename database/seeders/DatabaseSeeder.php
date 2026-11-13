<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // WithoutModelEvents (above) also mutes spatie's cache-forget hook, so a
        // seed run on an empty database would leave the permission cache holding
        // the pre-seed (empty) set and every later lookup would throw
        // "There is no permission named ...". Reset it around the role seed.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Locked system roles + delegation permissions (spec §0.3, WP-02).
        $this->call(RoleSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Shipped language packs: en + it + ja (spec §6.5, WP-20).
        $this->call(LanguageSeeder::class);

        // The demo admin is a local-development convenience only — never create
        // a known-credential account on a real deployment (the installer wizard
        // creates the real Super Admin instead, §6.9).
        if (app()->environment('local', 'testing')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

            // The workspace the customer panel signs in to (single company).
            $this->call(WorkspaceSeeder::class);
        }
    }
}
