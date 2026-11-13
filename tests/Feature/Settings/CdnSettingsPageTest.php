<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\CdnSettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Settings\SettingsReader;
use App\Settings\CdnSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// F-3.25.9-001
it('renders the CDN settings page for an admin', function (): void {
    $this->get(CdnSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.cdn.title'));
});

it('denies the customer guard access to the CDN settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(CdnSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('shows the seeded CDN defaults', function (): void {
    Livewire::test(CdnSettingsPage::class)
        ->assertFormSet([
            'enabled' => false,
            'base_url' => null,
            'subdomain' => null,
            'use_for_assets' => true,
            'use_for_email_images' => true,
            'provider' => 'custom',
        ]);
});

// F-3.25.9-001 / F-3.25.9-002
it('persists CDN settings including provider credentials', function (): void {
    Livewire::test(CdnSettingsPage::class)
        ->fillForm([
            'enabled' => true,
            'base_url' => 'https://cdn.example.com',
            'subdomain' => 'cdn',
            'use_for_assets' => true,
            'use_for_email_images' => false,
            'provider' => CdnSettings::PROVIDER_S3_CLOUDFRONT,
            's3_bucket' => 'acme-assets',
            's3_region' => 'eu-central-1',
            's3_access_key_id' => 'AKIAEXAMPLE',
            's3_secret_access_key' => 'super-secret-s3-key',
            'cloudfront_distribution_id' => 'E2EXAMPLE',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(CdnSettings::class)->refresh();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->base_url)->toBe('https://cdn.example.com')
        ->and($settings->subdomain)->toBe('cdn')
        ->and($settings->use_for_email_images)->toBeFalse()
        ->and($settings->provider)->toBe('s3-cloudfront')
        ->and($settings->s3_bucket)->toBe('acme-assets')
        ->and($settings->s3_secret_access_key)->toBe('super-secret-s3-key');
});

it('stores the S3 secret encrypted at rest', function (): void {
    Livewire::test(CdnSettingsPage::class)
        ->fillForm([
            'provider' => CdnSettings::PROVIDER_S3_CLOUDFRONT,
            's3_secret_access_key' => 'super-secret-s3-key',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $payload = (string) DB::table('settings')
        ->where('group', 'cdn')
        ->where('name', 's3_secret_access_key')
        ->value('payload');

    expect($payload)->not->toContain('super-secret-s3-key');
});

it('rejects an invalid CDN base URL', function (): void {
    Livewire::test(CdnSettingsPage::class)
        ->fillForm(['base_url' => 'not a url'])
        ->call('save')
        ->assertHasFormErrors(['base_url']);
});

// F-3.25.9-001 — cdnUrl() helper
it('builds CDN urls through the reader when active', function (): void {
    $settings = app(CdnSettings::class);
    $settings->enabled = true;
    $settings->base_url = 'https://cdn.example.com/';
    $settings->save();

    expect(SettingsReader::cdn()->active())->toBeTrue()
        ->and(SettingsReader::cdn()->cdnUrl('/img/logo.png'))->toBe('https://cdn.example.com/img/logo.png')
        ->and(SettingsReader::cdn()->cdnUrl('img/logo.png'))->toBe('https://cdn.example.com/img/logo.png');
});

it('falls back to the application asset url when the CDN is inactive', function (): void {
    expect(SettingsReader::cdn()->active())->toBeFalse()
        ->and(SettingsReader::cdn()->cdnUrl('img/logo.png'))->toBe(asset('img/logo.png'));

    // Enabled but without a base URL the CDN stays inactive.
    $settings = app(CdnSettings::class);
    $settings->enabled = true;
    $settings->save();

    expect(SettingsReader::cdn()->active())->toBeFalse()
        ->and(SettingsReader::cdn()->cdnUrl('img/logo.png'))->toBe(asset('img/logo.png'));
});
