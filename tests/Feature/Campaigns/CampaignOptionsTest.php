<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaign;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Settings\CampaignSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// §2.3.4 — campaign wizard step 3: tracking, delivery, content options, extras.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);
});

// F-2.3.4-001..004 — tracking toggles, URL exceptions, UTM params.
it('stores tracking options including url exceptions and utm parameters', function (): void {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'track_opens' => false,
            'track_clicks' => true,
            'url_tracking_exceptions' => "https://skip.me/a\nhttps://skip.me/b",
            'utm_enabled' => true,
            'utm_params.source' => 'newsletter',
            'utm_params.medium' => 'email',
            'utm_params.campaign' => 'august',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $campaign = $this->campaign->refresh();

    expect($campaign->track_opens)->toBeFalse()
        ->and($campaign->track_clicks)->toBeTrue()
        ->and($campaign->url_tracking_exceptions)->toBe(['https://skip.me/a', 'https://skip.me/b'])
        ->and($campaign->utm_enabled)->toBeTrue()
        ->and($campaign->utm_params['source'])->toBe('newsletter')
        ->and($campaign->utm_params['campaign'])->toBe('august');
});

// F-2.3.4-007..012 — delivery limits, throttle, time window, batching.
it('stores delivery throttle, window and batch options', function (): void {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'max_emails' => 5000,
            'send_rate_per_hour' => 1200,
            'send_window_days' => ['1', '2', '3'],
            'send_window_start' => '09:00',
            'send_window_end' => '17:00',
            'batch_size' => 500,
            'batch_interval_seconds' => 60,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $campaign = $this->campaign->refresh();

    expect($campaign->max_emails)->toBe(5000)
        ->and($campaign->send_rate_per_hour)->toBe(1200)
        ->and(array_map('intval', $campaign->send_window_days))->toBe([1, 2, 3])
        ->and($campaign->batch_size)->toBe(500)
        ->and($campaign->batch_interval_seconds)->toBe(60);
});

// F-2.3.4-018 — custom X-headers validate their name format.
it('accepts valid X-headers and rejects invalid header names', function (): void {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'custom_headers' => [
                ['name' => 'X-Campaign-Ref', 'value' => 'abc-123'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->campaign->refresh()->custom_headers)
        ->toBe([['name' => 'X-Campaign-Ref', 'value' => 'abc-123']]);

    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'custom_headers' => [
                ['name' => 'Not-An-X-Header', 'value' => 'nope'],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();
});

// F-2.3.4-016/017/019 — content option toggles.
it('stores embed-images, plain-text-only and auto-plain-text toggles', function (): void {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'embed_images' => true,
            'plain_text_only' => true,
            'auto_plain_text' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $campaign = $this->campaign->refresh();

    expect($campaign->embed_images)->toBeTrue()
        ->and($campaign->plain_text_only)->toBeTrue()
        ->and($campaign->auto_plain_text)->toBeFalse();
});

// F-2.3.4-015 — attachments enforce the §3.25.3 limits and create rows.
it('uploads attachments within the admin limits', function (): void {
    Storage::fake('public');

    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'attachments_upload' => [
                UploadedFile::fake()->create('brochure.pdf', 200, 'application/pdf'),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $attachment = $this->campaign->attachments()->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->path)->toContain('campaigns/'.$this->campaign->uid.'/attachments');

    Storage::disk('public')->assertExists($attachment->path);
});

it('rejects attachments of a disallowed type or oversize', function (): void {
    Storage::fake('public');

    // .exe is not in the allowed mime/extension lists.
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'attachments_upload' => [
                UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect($this->campaign->attachments()->count())->toBe(0);

    // Oversize (limit is MB → fake sizes are KB).
    $settings = app(CampaignSettings::class);
    $settings->attachment_max_size_mb = 1;
    $settings->save();

    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'attachments_upload' => [
                UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'),
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect($this->campaign->attachments()->count())->toBe(0);
});

// F-2.3.4-020/021/023 — extras: share secret, notify, notes.
it('generates a share secret when public reporting is enabled', function (): void {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'share_reports' => true,
            'notify_on_finish' => true,
            'notes' => 'Internal notes here',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $campaign = $this->campaign->refresh();

    expect($campaign->share_reports)->toBeTrue()
        ->and($campaign->share_reports_secret)->not->toBeNull()
        ->and(strlen((string) $campaign->share_reports_secret))->toBe(40)
        ->and($campaign->shareReportUrl())->toContain('/campaigns/reports/')
        ->and($campaign->notify_on_finish)->toBeTrue()
        ->and($campaign->notes)->toBe('Internal notes here');
});
