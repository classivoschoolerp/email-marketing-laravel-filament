<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\LocationSettingsPage;
use App\Models\Customer;
use App\Settings\LocationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;
use Tests\Support\MaxMindDbFixture;

// §3.23 F-3.23-001/002/003 — the Location settings page: provider select,
// encrypted credentials, database upload/update and the test-lookup tool.

it('saves the provider, credentials and enrichment toggles', function (): void {
    AdminTestHelpers::actingAdmin();

    $this->get(LocationSettingsPage::getUrl(panel: 'admin'))->assertOk();

    Livewire::test(LocationSettingsPage::class)
        ->fillForm([
            'provider' => LocationSettings::PROVIDER_IPINFO,
            'cache_ttl_minutes' => 30,
            'enrich_tracking' => true,
            'enrich_login_logs' => false,
            'ipinfo_token' => 'secret-token',
            'maxmind_edition' => 'GeoLite2-City',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(LocationSettings::class)->refresh();

    expect($settings->provider)->toBe(LocationSettings::PROVIDER_IPINFO)
        ->and($settings->cache_ttl_minutes)->toBe(30)
        ->and($settings->enrich_login_logs)->toBeFalse()
        ->and($settings->ipinfo_token)->toBe('secret-token');

    // Credentials are stored encrypted (§3.23 + §0.1 F-0.1-019).
    expect(LocationSettings::encrypted())->toContain('ipinfo_token', 'maxmind_license_key', 'ipapi_key');

    $stored = (string) DB::table('settings')
        ->where('group', 'location')->where('name', 'ipinfo_token')->value('payload');

    expect($stored)->not->toContain('secret-token');
});

it('rejects an unknown provider key', function (): void {
    AdminTestHelpers::actingAdmin();

    Livewire::test(LocationSettingsPage::class)
        ->fillForm(['provider' => 'not-a-provider'])
        ->call('save')
        ->assertHasFormErrors(['provider']);
});

it('runs the test lookup tool and reports failures honestly', function (): void {
    AdminTestHelpers::actingAdmin();

    Http::fake(['ip-api.com/*' => Http::response([
        'status' => 'success',
        'country' => 'Germany',
        'countryCode' => 'DE',
        'regionName' => 'Berlin',
        'city' => 'Berlin',
        'timezone' => 'Europe/Berlin',
    ])]);

    $settings = app(LocationSettings::class);
    $settings->provider = LocationSettings::PROVIDER_IP_API;
    $settings->save();

    $page = Livewire::test(LocationSettingsPage::class);

    // Invalid input never reaches the provider.
    $page->set('testIp', 'not-an-ip')->call('runTestLookup')
        ->assertSet('testError', __('settings.location.errors.invalid_ip'))
        ->assertSet('testResult', null);

    $page->set('testIp', '91.0.0.1')->call('runTestLookup')
        ->assertSet('testError', null)
        ->assertSee('Berlin')
        ->assertSee('Europe/Berlin');

    expect($page->get('testResult')['country_code'])->toBe('DE')
        ->and($page->get('testResult')['provider'])->toBe('ip-api');

});

it('surfaces the provider error message in the test lookup tool', function (): void {
    AdminTestHelpers::actingAdmin();

    Http::fake(['ip-api.com/*' => Http::response('boom', 500)]);

    $settings = app(LocationSettings::class);
    $settings->provider = LocationSettings::PROVIDER_IP_API;
    $settings->save();

    $page = Livewire::test(LocationSettingsPage::class)
        ->set('testIp', '91.0.0.2')
        ->call('runTestLookup');

    expect((string) $page->get('testError'))->toContain('HTTP 500');
});

it('shows the uploaded database status and can remove it', function (): void {
    AdminTestHelpers::actingAdmin();

    $relative = 'geoip/GeoLite2-City.mmdb';
    MaxMindDbFixture::cityDatabase(Storage::disk('local')->path($relative));

    $settings = app(LocationSettings::class);
    $settings->provider = LocationSettings::PROVIDER_MAXMIND;
    $settings->maxmind_database_path = $relative;
    $settings->save();

    $page = Livewire::test(LocationSettingsPage::class);

    $status = $page->instance()->databaseStatus();

    expect($status['exists'])->toBeTrue()
        ->and($status['size'])->toBeGreaterThan(0)
        ->and($status['info']['type'])->toBe('GeoLite2-City-Test')
        ->and($page->instance()->providerConfigured())->toBeTrue();

    $page->call('deleteDatabase');

    expect(app(LocationSettings::class)->refresh()->maxmind_database_path)->toBeNull()
        ->and(Storage::disk('local')->exists($relative))->toBeFalse();
});

it('rejects a downloaded archive that contains no database', function (): void {
    AdminTestHelpers::actingAdmin();

    Http::fake(['download.maxmind.com/*' => Http::response(gzencode('NOT-AN-ARCHIVE'), 200)]);

    $settings = app(LocationSettings::class);
    $settings->provider = LocationSettings::PROVIDER_MAXMIND;
    $settings->maxmind_license_key = 'key-123';
    $settings->save();

    Livewire::test(LocationSettingsPage::class)->callAction('updateMaxmind');

    expect(app(LocationSettings::class)->refresh()->maxmind_database_path)->toBeNull();
});

it('updates the MaxMind database from the page action', function (): void {
    AdminTestHelpers::actingAdmin();

    Http::fake(['download.maxmind.com/*' => Http::response("\xAB\xCD\xEFMaxMind.com-fake-body", 200)]);

    $settings = app(LocationSettings::class);
    $settings->provider = LocationSettings::PROVIDER_MAXMIND;
    $settings->maxmind_license_key = 'key-123';
    $settings->save();

    Livewire::test(LocationSettingsPage::class)->callAction('updateMaxmind');

    expect(app(LocationSettings::class)->refresh()->maxmind_database_path)->toBe('geoip/GeoLite2-City.mmdb');

    Storage::disk('local')->delete('geoip/GeoLite2-City.mmdb');
});

it('denies the location settings page to customer-guard users', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(LocationSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});
