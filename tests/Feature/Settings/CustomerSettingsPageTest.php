<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\CustomerSettingsPage;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Settings\CustomerSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the customer settings page for an admin', function (): void {
    $this->get(CustomerSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.customer.title'));
});

it('shows the seeded defaults in the form', function (): void {
    Livewire::test(CustomerSettingsPage::class)
        ->assertFormSet([
            'registration_enabled' => true,
            'registration_requires_approval' => false,
            'registration_requires_email_confirmation' => true,
            'default_customer_group_id' => null,
            'allowed_sending_server_types' => CustomerSettings::SENDING_SERVER_TYPES,
            'default_max_lists' => 0,
            'default_sending_quota_period' => 'monthly',
            'default_api_enabled' => true,
            'force_system_servers_only' => false,
            'welcome_email_enabled' => true,
            'self_serve_deletion_enabled' => true,
            'terms_acceptance_required' => true,
            'minimum_password_strength' => 'medium',
            'inactive_auto_suspend_days' => 0,
        ]);
});

it('persists customer settings', function (): void {
    $group = CustomerGroup::factory()->create();

    Livewire::test(CustomerSettingsPage::class)
        ->fillForm([
            'registration_enabled' => false,
            'registration_requires_approval' => true,
            'registration_requires_email_confirmation' => false,
            'default_customer_group_id' => $group->id,
            'allowed_sending_server_types' => ['smtp', 'amazon-ses-api'],
            'default_max_lists' => 10,
            'default_max_campaigns' => 20,
            'default_max_subscribers' => 100000,
            'default_sending_quota' => 50000,
            'default_sending_quota_period' => 'daily',
            'default_cdn_enabled' => true,
            'default_cdn_url' => 'https://cdn.acme.example',
            'default_api_enabled' => false,
            'default_api_rate_limit_per_minute' => 120,
            'force_system_servers_only' => true,
            'welcome_email_enabled' => false,
            'self_serve_deletion_enabled' => false,
            'terms_acceptance_required' => false,
            'minimum_password_strength' => 'strong',
            'inactive_auto_suspend_days' => 90,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(CustomerSettings::class)->refresh();

    expect($settings->registration_enabled)->toBeFalse()
        ->and($settings->registration_requires_approval)->toBeTrue()
        ->and($settings->registration_requires_email_confirmation)->toBeFalse()
        ->and($settings->default_customer_group_id)->toBe($group->id)
        ->and($settings->allowed_sending_server_types)->toBe(['smtp', 'amazon-ses-api'])
        ->and($settings->default_max_lists)->toBe(10)
        ->and($settings->default_max_campaigns)->toBe(20)
        ->and($settings->default_max_subscribers)->toBe(100000)
        ->and($settings->default_sending_quota)->toBe(50000)
        ->and($settings->default_sending_quota_period)->toBe('daily')
        ->and($settings->default_cdn_enabled)->toBeTrue()
        ->and($settings->default_cdn_url)->toBe('https://cdn.acme.example')
        ->and($settings->default_api_enabled)->toBeFalse()
        ->and($settings->default_api_rate_limit_per_minute)->toBe(120)
        ->and($settings->force_system_servers_only)->toBeTrue()
        ->and($settings->welcome_email_enabled)->toBeFalse()
        ->and($settings->self_serve_deletion_enabled)->toBeFalse()
        ->and($settings->terms_acceptance_required)->toBeFalse()
        ->and($settings->minimum_password_strength)->toBe('strong')
        ->and($settings->inactive_auto_suspend_days)->toBe(90);
});

it('allows clearing the default customer group', function (): void {
    $group = CustomerGroup::factory()->create();

    $settings = app(CustomerSettings::class);
    $settings->default_customer_group_id = $group->id;
    $settings->save();

    Livewire::test(CustomerSettingsPage::class)
        ->fillForm(['default_customer_group_id' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(CustomerSettings::class)->refresh()->default_customer_group_id)->toBeNull();
});

it('rejects invalid customer input', function (): void {
    Livewire::test(CustomerSettingsPage::class)
        ->fillForm([
            'default_customer_group_id' => 999999,
            'allowed_sending_server_types' => ['carrier-pigeon'],
            'default_max_lists' => -1,
            'default_sending_quota' => -5,
            'default_sending_quota_period' => 'yearly',
            'default_cdn_url' => 'not-a-url',
            'default_api_rate_limit_per_minute' => 0,
            'minimum_password_strength' => 'titanium',
            'inactive_auto_suspend_days' => -1,
        ])
        ->call('save')
        ->assertHasFormErrors([
            'default_customer_group_id',
            'allowed_sending_server_types.0',
            'default_max_lists',
            'default_sending_quota',
            'default_sending_quota_period',
            'default_cdn_url',
            'default_api_rate_limit_per_minute',
            'minimum_password_strength',
            'inactive_auto_suspend_days',
        ]);

    expect(app(CustomerSettings::class)->refresh()->minimum_password_strength)->toBe('medium');
});
