<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\CommonSettingsPage;
use App\Models\User;
use App\Settings\CommonSettings;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the common settings page for an admin', function (): void {
    $this->get(CommonSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.common.title'));
});

it('shows the seeded defaults in the form', function (): void {
    Livewire::test(CommonSettingsPage::class)
        ->assertFormSet([
            'site_name' => 'Email Marketing Platform',
            'default_timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'first_day_of_week' => 1,
            'default_language' => 'en',
            'clean_urls' => true,
            'site_offline' => false,
            'api_offline' => false,
            'grid_page_size' => 25,
            'auto_update' => false,
        ]);
});

it('persists common settings', function (): void {
    Livewire::test(CommonSettingsPage::class)
        ->fillForm([
            'site_name' => 'Acme Mailer',
            'site_description' => 'Bulk email for Acme',
            'site_tagline' => 'Send it right',
            'site_keywords' => 'email, marketing',
            'company_name' => 'Acme GmbH',
            'company_address' => "Musterstrasse 1\n10115 Berlin",
            'company_phone' => '+49 30 1234567',
            'company_email' => 'info@acme.example',
            'company_vat' => 'DE123456789',
            'default_timezone' => 'Europe/Berlin',
            'date_format' => 'd/m/Y',
            'time_format' => 'g:i A',
            'first_day_of_week' => 0,
            'default_language' => 'en',
            'clean_urls' => false,
            'grid_page_size' => 50,
            'auto_update' => true,
            'maintenance_allowed_ips' => ['203.0.113.10'],
            'recaptcha_site_key' => 'recaptcha-site-key',
            'recaptcha_secret_key' => 'recaptcha-secret-key',
            'admin_notification_emails' => ['ops@acme.example', 'alerts@acme.example'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(CommonSettings::class)->refresh();

    expect($settings->site_name)->toBe('Acme Mailer')
        ->and($settings->site_description)->toBe('Bulk email for Acme')
        ->and($settings->site_tagline)->toBe('Send it right')
        ->and($settings->site_keywords)->toBe('email, marketing')
        ->and($settings->company_name)->toBe('Acme GmbH')
        ->and($settings->company_email)->toBe('info@acme.example')
        ->and($settings->company_vat)->toBe('DE123456789')
        ->and($settings->default_timezone)->toBe('Europe/Berlin')
        ->and($settings->date_format)->toBe('d/m/Y')
        ->and($settings->time_format)->toBe('g:i A')
        ->and($settings->first_day_of_week)->toBe(0)
        ->and($settings->clean_urls)->toBeFalse()
        ->and($settings->grid_page_size)->toBe(50)
        ->and($settings->auto_update)->toBeTrue()
        ->and($settings->maintenance_allowed_ips)->toBe(['203.0.113.10'])
        ->and($settings->recaptcha_site_key)->toBe('recaptcha-site-key')
        ->and($settings->recaptcha_secret_key)->toBe('recaptcha-secret-key')
        ->and($settings->admin_notification_emails)->toBe(['ops@acme.example', 'alerts@acme.example']);
});

it('stores the recaptcha secret key encrypted at rest', function (): void {
    Livewire::test(CommonSettingsPage::class)
        ->fillForm(['recaptcha_secret_key' => 'super-secret-value'])
        ->call('save')
        ->assertHasNoFormErrors();

    $payload = (string) DB::table('settings')
        ->where('group', 'common')
        ->where('name', 'recaptcha_secret_key')
        ->value('payload');

    expect($payload)->not->toContain('super-secret-value')
        ->and(app(CommonSettings::class)->refresh()->recaptcha_secret_key)->toBe('super-secret-value');
});

it('stores uploaded logo and favicon on the public disk', function (): void {
    Storage::fake('public');

    Livewire::test(CommonSettingsPage::class)
        ->fillForm([
            'logo_path' => UploadedFile::fake()->image('logo.png', 200, 60),
            'favicon_path' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(CommonSettings::class)->refresh();

    expect($settings->logo_path)->toBeString()->toStartWith('branding/')
        ->and($settings->favicon_path)->toBeString()->toStartWith('branding/');

    Storage::disk('public')->assertExists($settings->logo_path);
    Storage::disk('public')->assertExists($settings->favicon_path);
});

it('rejects invalid common settings input', function (): void {
    Livewire::test(CommonSettingsPage::class)
        ->fillForm([
            'site_name' => '',
            'company_email' => 'not-an-email',
            'default_timezone' => 'Not/A_Zone',
            'grid_page_size' => 33,
            'site_offline_message' => '',
            'maintenance_allowed_ips' => ['999.999.1.1'],
            'admin_notification_emails' => ['not-an-email'],
        ])
        ->call('save')
        ->assertHasFormErrors([
            'site_name',
            'company_email',
            'default_timezone',
            'grid_page_size',
            'site_offline_message',
            'maintenance_allowed_ips.0',
            'admin_notification_emails.0',
        ]);

    expect(app(CommonSettings::class)->refresh()->site_name)->toBe('Email Marketing Platform');
});

it('blocks guests from the common settings page', function (): void {
    auth('admin')->logout();

    $this->get(CommonSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});
