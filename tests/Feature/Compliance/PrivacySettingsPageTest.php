<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\PrivacySettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Settings\SettingsReader;
use App\Settings\PrivacySettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * WP-22 — §3.25.16 privacy / GDPR settings page (F-3.25.16-001 … 005) plus
 * the §4.5 portability + CAN-SPAM switches.
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the privacy settings page for an admin', function (): void {
    $this->get(PrivacySettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.privacy.title'));
});

it('denies the customer guard access to the privacy settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(PrivacySettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

// Everything ships OFF so an existing install behaves as before.
it('seeds compliance toggles off and retention as config-inheriting', function (): void {
    $privacy = SettingsReader::privacy();

    expect($privacy->cookieConsentEnabled())->toBeFalse()
        ->and($privacy->erasureWorkflowEnabled())->toBeFalse()
        ->and($privacy->anonymizeIps())->toBeFalse()
        ->and($privacy->canSpamEnforceAddress())->toBeFalse()
        ->and($privacy->dataExportEnabled())->toBeTrue()
        ->and($privacy->dpaText())->toBeNull()
        ->and($privacy->subProcessorsText())->toBeNull()
        // NULL retention inherits the WP-13 config windows.
        ->and($privacy->deliveryLogRetentionDays())
        ->toBe((int) config('emp-sending.retention.campaign_delivery_log_days'))
        ->and($privacy->openRetentionDays())
        ->toBe((int) config('emp-sending.retention.campaign_track_days'))
        ->and($privacy->clickRetentionDays())
        ->toBe((int) config('emp-sending.retention.campaign_track_days'))
        ->and($privacy->exportRetentionDays())->toBe(0);
});

// F-3.25.16-001 … 005
it('persists every privacy setting', function (): void {
    Livewire::test(PrivacySettingsPage::class)
        ->fillForm([
            'cookie_consent_enabled' => true,
            'cookie_consent_text' => 'We use cookies.',
            'cookie_policy_url' => 'https://example.com/cookies',
            'retention_delivery_log_days' => 45,
            'retention_open_days' => 60,
            'retention_click_days' => 75,
            'retention_export_days' => 7,
            'erasure_workflow_enabled' => true,
            'data_export_enabled' => true,
            'anonymize_ips' => true,
            'canspam_enforce_address' => true,
            'dpa_text' => 'This DPA governs processing.',
            'sub_processors_text' => "Acme Cloud\nBeta Mail",
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(PrivacySettings::class)->refresh();

    expect($settings->cookie_consent_enabled)->toBeTrue()
        ->and($settings->cookie_consent_text)->toBe('We use cookies.')
        ->and($settings->cookie_policy_url)->toBe('https://example.com/cookies')
        ->and($settings->retention_delivery_log_days)->toBe(45)
        ->and($settings->retention_open_days)->toBe(60)
        ->and($settings->retention_click_days)->toBe(75)
        ->and($settings->retention_export_days)->toBe(7)
        ->and($settings->erasure_workflow_enabled)->toBeTrue()
        ->and($settings->anonymize_ips)->toBeTrue()
        ->and($settings->canspam_enforce_address)->toBeTrue()
        ->and($settings->dpa_text)->toBe('This DPA governs processing.')
        ->and($settings->sub_processors_text)->toBe("Acme Cloud\nBeta Mail");
});

// NULL retention must survive a save (empty input = inherit config).
it('stores an empty retention field as null so config wins', function (): void {
    Livewire::test(PrivacySettingsPage::class)
        ->fillForm(['retention_open_days' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(PrivacySettings::class)->refresh()->retention_open_days)->toBeNull()
        ->and(SettingsReader::privacy()->openRetentionDays())
        ->toBe((int) config('emp-sending.retention.campaign_track_days'));
});
