<?php

declare(strict_types=1);

use App\Enums\LoginResult;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Settings\SecuritySettings;
use Illuminate\Support\Facades\DB;

/*
 * WP-22 — F-3.25.15-007: audit log retention, consumed by `logs:prune`.
 */

it('deletes audit rows past the configured retention window', function (): void {
    $customer = Customer::factory()->create();

    // Two sign-in records: one old, one recent.
    LoginLog::query()->create([
        'guard' => 'customer',
        'email' => 'old@example.com',
        'ip' => '203.0.113.7',
        'result' => LoginResult::Success,
        'created_at' => now()->subDays(90),
    ]);
    LoginLog::query()->create([
        'guard' => 'customer',
        'email' => 'fresh@example.com',
        'ip' => '203.0.113.8',
        'result' => LoginResult::Success,
        'created_at' => now()->subDay(),
    ]);

    // Same for the consent audit trail.
    foreach ([90, 1] as $age) {
        ConsentEvent::withoutTenancy()->create([
            'customer_id' => $customer->id,
            'subscriber_id' => 1,
            'field' => 'GDPR',
            'old_value' => null,
            'new_value' => '1',
            'source' => ConsentEvent::SOURCE_PUBLIC,
            'created_at' => now()->subDays($age),
        ]);
    }

    // Impersonation records use the standard timestamps pair.
    DB::table('impersonation_logs')->insert([
        ['admin_id' => null, 'customer_id' => $customer->id, 'started_at' => now()->subDays(90), 'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90)],
        ['admin_id' => null, 'customer_id' => $customer->id, 'started_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
    ]);

    $settings = app(SecuritySettings::class);
    $settings->audit_log_retention_days = 30;
    $settings->save();

    $this->artisan('logs:prune')->assertSuccessful();

    expect(LoginLog::query()->count())->toBe(1)
        ->and(LoginLog::query()->value('email'))->toBe('fresh@example.com')
        ->and(ConsentEvent::withoutTenancy()->count())->toBe(1)
        ->and(DB::table('impersonation_logs')->count())->toBe(1);
});

// D-23: 0 = keep audit records forever.
it('keeps every audit row when retention is zero', function (): void {
    LoginLog::query()->create([
        'guard' => 'admin',
        'email' => 'ancient@example.com',
        'result' => LoginResult::Failed,
        'created_at' => now()->subYears(5),
    ]);

    $settings = app(SecuritySettings::class);
    $settings->audit_log_retention_days = 0;
    $settings->save();

    $this->artisan('logs:prune')->assertSuccessful();

    expect(LoginLog::query()->count())->toBe(1);
});
