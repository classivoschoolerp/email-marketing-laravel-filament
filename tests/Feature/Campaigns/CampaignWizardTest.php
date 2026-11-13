<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Pages\CreateCampaign;
use App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaign;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Settings\CampaignSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

// §2.3.2 — campaign wizard step 1 (details) + quotas and settings gates.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

function wp11_campaign_form(array $overrides = []): array
{
    return array_merge([
        'name' => 'August newsletter',
        'type' => CampaignType::Regular->value,
        'email_list_id' => test()->list->id,
        'from_name' => 'Acme',
        'from_email' => 'news@acme.test',
        'reply_to' => 'reply@acme.test',
        'to_name' => '{SUBSCRIBER_NAME}',
        'subject' => 'Hello there 🎉',
        'preheader' => 'A quick look inside',
        'send_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'timezone' => 'UTC',
        'builder' => 'html',
        'content_html' => '<p>Hi {SUBSCRIBER_NAME}</p><p><a href="{UNSUBSCRIBE_URL}">bye</a></p>',
    ], $overrides);
}

// F-2.3.2-001..010 — the wizard creates a draft with all detail fields.
it('creates a draft regular campaign through the wizard', function (): void {
    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form())
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::query()->firstOrFail();

    expect($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->customer_id)->toBe($this->customer->id)
        ->and($campaign->type)->toBe(CampaignType::Regular)
        ->and($campaign->subject)->toBe('Hello there 🎉')
        ->and($campaign->uid)->not->toBeEmpty()
        ->and($campaign->email_list_id)->toBe($this->list->id);
});

// F-2.3.2-014..018 — autoresponder-specific fields persist.
it('stores autoresponder configuration', function (): void {
    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form([
            'type' => CampaignType::Autoresponder->value,
            'ar_trigger' => 'after-subscribe',
            'ar_delay_value' => 2,
            'ar_delay_unit' => 'days',
            'ar_send_time' => '09:00',
            'ar_apply_to' => 'new',
            'ar_once_per_subscriber' => true,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::query()->firstOrFail();

    expect($campaign->type)->toBe(CampaignType::Autoresponder)
        ->and($campaign->ar_trigger->value)->toBe('after-subscribe')
        ->and($campaign->ar_delay_value)->toBe(2)
        ->and($campaign->ar_delay_unit->value)->toBe('days')
        ->and($campaign->ar_apply_to)->toBe('new')
        ->and($campaign->ar_once_per_subscriber)->toBeTrue();
});

// F-2.3.2-019..022 — recurring fields persist and next_run_at is computed.
it('stores recurring configuration and computes the next run on edit save', function (): void {
    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form([
            'type' => CampaignType::Recurring->value,
            'rec_interval' => 'daily',
            'rec_starts_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'rec_max_occurrences' => 10,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::query()->firstOrFail();
    expect($campaign->rec_interval->value)->toBe('daily')
        ->and($campaign->rec_max_occurrences)->toBe(10);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($campaign->refresh()->next_run_at)->not->toBeNull();
});

// F-2.3.2-019 — invalid custom cron is rejected inline.
it('rejects an invalid custom cron expression', function (): void {
    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form([
            'type' => CampaignType::Recurring->value,
            'rec_interval' => 'custom-cron',
            'rec_cron' => 'definitely not cron',
        ]))
        ->call('create')
        ->assertHasFormErrors(['rec_cron']);

    expect(Campaign::query()->count())->toBe(0);
});

// F-2.3.2-005 — segments must belong to the selected list.
it('rejects a segment that belongs to another list', function (): void {
    $otherList = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $foreignSegment = ListSegment::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $otherList->id,
    ]);

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form(['list_segment_id' => $foreignSegment->id]))
        ->call('create')
        ->assertHasFormErrors(['list_segment_id']);
});

// F-2.3.2-013 — blacklisted subject words warn in flag mode, block in block mode.
it('blocks blacklisted subject words in block mode and allows them in flag mode', function (): void {
    $settings = app(CampaignSettings::class);
    $settings->subject_blacklisted_words = ['free money'];
    $settings->blacklisted_words_action = CampaignSettings::BLACKLISTED_WORDS_ACTION_BLOCK;
    $settings->save();

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form(['subject' => 'Get FREE MONEY now']))
        ->call('create')
        ->assertHasFormErrors(['subject']);

    expect(Campaign::query()->count())->toBe(0);

    $settings->blacklisted_words_action = CampaignSettings::BLACKLISTED_WORDS_ACTION_FLAG;
    $settings->save();

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form(['subject' => 'Get FREE MONEY now']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Campaign::query()->count())->toBe(1);
});

// F-3.25.3-009 — minimum future offset for send_at.
it('enforces the send-at minimum future offset setting', function (): void {
    $settings = app(CampaignSettings::class);
    $settings->send_at_min_offset_minutes = 60;
    $settings->save();

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form(['send_at' => now()->addMinutes(10)->format('Y-m-d H:i:s')]))
        ->call('create')
        ->assertHasFormErrors(['send_at']);

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form(['send_at' => now()->addMinutes(90)->format('Y-m-d H:i:s')]))
        ->call('create')
        ->assertHasNoFormErrors();
});

// F-0.3-005 — max_campaigns quota.
it('refuses creation beyond the max_campaigns quota', function (): void {
    $this->customer->limitOverrides()->create(['key' => 'max_campaigns', 'value' => 1]);

    Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form())
        ->call('create')
        ->assertHasFormErrors();

    expect(Campaign::query()->count())->toBe(1);
});

// §3.5 — campaign types can be disabled per group/customer.
it('refuses a campaign type the customer group does not allow', function (): void {
    $this->customer->permissionOverrides()->create(['key' => 'campaigns.recurring', 'allowed' => false]);

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_campaign_form([
            'type' => CampaignType::Recurring->value,
            'rec_interval' => 'daily',
            'rec_starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasFormErrors();

    expect(Campaign::query()->count())->toBe(0);
});

// Tenancy — B cannot open A's campaign wizard.
it('denies editing another tenants campaign', function (): void {
    $other = Customer::factory()->create();
    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Campaign => Campaign::factory()->create(['customer_id' => $other->id]),
    );

    $this->get(CampaignResource::getUrl('edit', ['record' => $foreign]))
        ->assertNotFound();
});

// §2.3.9 — campaigns are editable only until sending.
it('bounces non-editable campaigns off the edit page', function (): void {
    $sending = Campaign::factory()->sending()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    Livewire::test(EditCampaign::class, ['record' => $sending->getRouteKey()])
        ->assertRedirect(CampaignResource::getUrl('index'));
});
