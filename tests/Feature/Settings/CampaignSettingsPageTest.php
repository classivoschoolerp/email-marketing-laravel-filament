<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\CampaignSettingsPage;
use App\Models\User;
use App\Settings\CampaignSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the campaign settings page for an admin', function (): void {
    $this->get(CampaignSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.campaign.title'));
});

it('shows the seeded defaults in the form', function (): void {
    Livewire::test(CampaignSettingsPage::class)
        ->assertFormSet([
            'attachments_enabled' => true,
            'attachment_max_size_mb' => 10,
            'attachment_max_count' => 5,
            'enabled_template_tags' => CampaignSettings::KNOWN_TEMPLATE_TAGS,
            'blacklisted_words_action' => 'block',
            'webhooks_enabled' => false,
            'default_open_tracking' => true,
            'default_click_tracking' => true,
            'max_campaign_recipients' => 0,
            'approval_required' => false,
            'send_at_min_offset_minutes' => 0,
            'default_plain_text_generation' => true,
            'retry_max_attempts' => 3,
            'retry_backoff_seconds' => 300,
        ]);
});

it('persists campaign settings', function (): void {
    Livewire::test(CampaignSettingsPage::class)
        ->fillForm([
            'attachments_enabled' => false,
            'attachment_max_size_mb' => 25,
            'attachment_max_count' => 3,
            'attachment_allowed_mime_types' => ['application/pdf'],
            'attachment_allowed_extensions' => ['pdf'],
            'enabled_template_tags' => ['SUBSCRIBER_EMAIL', 'UNSUBSCRIBE_URL'],
            'subject_blacklisted_words' => ['free money', 'winner'],
            'content_blacklisted_words' => ['casino'],
            'blacklisted_words_action' => 'flag',
            'forbidden_from_domains' => ['gmail.com'],
            'forbidden_reply_to_domains' => ['yahoo.com'],
            'webhooks_enabled' => true,
            'webhook_endpoints' => ['https://hooks.example.com/campaigns'],
            'default_open_tracking' => false,
            'default_click_tracking' => false,
            'max_campaign_recipients' => 50000,
            'approval_required' => true,
            'send_at_min_offset_minutes' => 15,
            'bounce_auto_pause_percent' => 2.5,
            'complaint_auto_pause_percent' => 0.1,
            'default_plain_text_generation' => false,
            'retry_max_attempts' => 5,
            'retry_backoff_seconds' => 600,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(CampaignSettings::class)->refresh();

    expect($settings->attachments_enabled)->toBeFalse()
        ->and($settings->attachment_max_size_mb)->toBe(25)
        ->and($settings->attachment_max_count)->toBe(3)
        ->and($settings->attachment_allowed_mime_types)->toBe(['application/pdf'])
        ->and($settings->attachment_allowed_extensions)->toBe(['pdf'])
        ->and($settings->enabled_template_tags)->toBe(['SUBSCRIBER_EMAIL', 'UNSUBSCRIBE_URL'])
        ->and($settings->subject_blacklisted_words)->toBe(['free money', 'winner'])
        ->and($settings->content_blacklisted_words)->toBe(['casino'])
        ->and($settings->blacklisted_words_action)->toBe('flag')
        ->and($settings->forbidden_from_domains)->toBe(['gmail.com'])
        ->and($settings->forbidden_reply_to_domains)->toBe(['yahoo.com'])
        ->and($settings->webhooks_enabled)->toBeTrue()
        ->and($settings->webhook_endpoints)->toBe(['https://hooks.example.com/campaigns'])
        ->and($settings->default_open_tracking)->toBeFalse()
        ->and($settings->default_click_tracking)->toBeFalse()
        ->and($settings->max_campaign_recipients)->toBe(50000)
        ->and($settings->approval_required)->toBeTrue()
        ->and($settings->send_at_min_offset_minutes)->toBe(15)
        ->and($settings->bounce_auto_pause_percent)->toBe(2.5)
        ->and($settings->complaint_auto_pause_percent)->toBe(0.1)
        ->and($settings->default_plain_text_generation)->toBeFalse()
        ->and($settings->retry_max_attempts)->toBe(5)
        ->and($settings->retry_backoff_seconds)->toBe(600);
});

it('rejects invalid campaign input', function (): void {
    Livewire::test(CampaignSettingsPage::class)
        ->fillForm([
            'attachment_max_size_mb' => 0,
            'attachment_max_count' => 0,
            'attachment_allowed_mime_types' => ['not a mime type'],
            'enabled_template_tags' => ['NOT_A_KNOWN_TAG'],
            'blacklisted_words_action' => 'nuke',
            'forbidden_from_domains' => ['not a domain'],
            'webhook_endpoints' => ['not-a-url'],
            'max_campaign_recipients' => -1,
            'send_at_min_offset_minutes' => -10,
            'bounce_auto_pause_percent' => 150,
            'complaint_auto_pause_percent' => -1,
            'retry_max_attempts' => 0,
            'retry_backoff_seconds' => -60,
        ])
        ->call('save')
        ->assertHasFormErrors([
            'attachment_max_size_mb',
            'attachment_max_count',
            'attachment_allowed_mime_types.0',
            'enabled_template_tags.0',
            'blacklisted_words_action',
            'forbidden_from_domains.0',
            'webhook_endpoints.0',
            'max_campaign_recipients',
            'send_at_min_offset_minutes',
            'bounce_auto_pause_percent',
            'complaint_auto_pause_percent',
            'retry_max_attempts',
            'retry_backoff_seconds',
        ]);

    expect(app(CampaignSettings::class)->refresh()->blacklisted_words_action)->toBe('block');
});
