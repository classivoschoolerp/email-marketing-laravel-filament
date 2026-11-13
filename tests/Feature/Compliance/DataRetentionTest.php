<?php

declare(strict_types=1);

use App\Models\ExportJob;
use App\Settings\PrivacySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * WP-22 — F-3.25.16-002 / F-4.5-007: data retention & auto-purge. The WP-13
 * `logs:prune` command reads the privacy settings per data type, with the
 * config/emp-sending.php windows as the fallback.
 */

function seedTrackRow(string $table, int $daysAgo): void
{
    $row = [
        'campaign_uid' => 'camp-'.$daysAgo,
        'subscriber_uid' => 'sub-'.$daysAgo,
        'created_at' => now()->subDays($daysAgo),
    ];

    if ($table === 'campaign_track_clicks') {
        $row['url_id'] = DB::table('campaign_track_urls')->insertGetId([
            'campaign_uid' => $row['campaign_uid'],
            'url' => 'https://example.com/'.$daysAgo,
            'hash' => sha1('url-'.$daysAgo),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table($table)->insert($row);
}

it('inherits the config windows while the settings are null', function (): void {
    // Default config: opens/clicks 180 days, delivery logs 90 days.
    seedTrackRow('campaign_track_opens', 200);
    seedTrackRow('campaign_track_opens', 10);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(DB::table('campaign_track_opens')->count())->toBe(1);
});

// F-3.25.16-002 — per-data-type windows override the config.
it('honours a per-data-type retention override', function (): void {
    seedTrackRow('campaign_track_opens', 20);
    seedTrackRow('campaign_track_opens', 3);
    seedTrackRow('campaign_track_clicks', 20);
    seedTrackRow('campaign_track_clicks', 3);

    $settings = app(PrivacySettings::class);
    $settings->retention_open_days = 7;    // tighter than clicks
    $settings->retention_click_days = 365; // looser than the config default
    $settings->save();

    $this->artisan('logs:prune')->assertSuccessful();

    expect(DB::table('campaign_track_opens')->count())->toBe(1)
        ->and(DB::table('campaign_track_clicks')->count())->toBe(2);
});

it('prunes delivery logs on the configured window', function (): void {
    DB::table('campaign_delivery_logs')->insert([
        ['campaign_id' => 1, 'subscriber_id' => 1, 'status' => 'sent', 'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)],
        ['campaign_id' => 1, 'subscriber_id' => 2, 'status' => 'sent', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
    ]);

    $settings = app(PrivacySettings::class);
    $settings->retention_delivery_log_days = 30;
    $settings->save();

    $this->artisan('logs:prune')->assertSuccessful();

    expect(DB::table('campaign_delivery_logs')->count())->toBe(1);
});

// D-23: 0 = keep forever.
it('keeps rows forever when a window is set to zero', function (): void {
    seedTrackRow('campaign_track_opens', 5000);

    $settings = app(PrivacySettings::class);
    $settings->retention_open_days = 0;
    $settings->save();

    $this->artisan('logs:prune')->assertSuccessful();

    expect(DB::table('campaign_track_opens')->count())->toBe(1);
});

// F-3.25.16-002 (exports) — the file goes, the history row survives.
it('expires export files past the export retention window', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('customers/x/exports/old.csv', 'email\n');
    Storage::disk('local')->put('customers/x/exports/new.csv', 'email\n');

    $customer = \App\Models\Customer::factory()->create();

    $old = ExportJob::withoutTenancy()->create([
        'uid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'status' => ExportJob::STATUS_COMPLETED,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
        'file_path' => 'customers/x/exports/old.csv',
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(30),
    ]);

    $new = ExportJob::withoutTenancy()->create([
        'uid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'status' => ExportJob::STATUS_COMPLETED,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
        'file_path' => 'customers/x/exports/new.csv',
    ]);

    $settings = app(PrivacySettings::class);
    $settings->retention_export_days = 7;
    $settings->save();

    $this->artisan('logs:prune')->assertSuccessful();

    expect($old->fresh()->status)->toBe(ExportJob::STATUS_EXPIRED)
        ->and($old->fresh()->file_path)->toBeNull()
        ->and(Storage::disk('local')->exists('customers/x/exports/old.csv'))->toBeFalse()
        ->and($new->fresh()->status)->toBe(ExportJob::STATUS_COMPLETED)
        ->and(Storage::disk('local')->exists('customers/x/exports/new.csv'))->toBeTrue();
});
