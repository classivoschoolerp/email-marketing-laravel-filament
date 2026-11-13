<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\CampaignResource\Pages\ListCampaigns;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Campaigns\CampaignCopier;
use App\Services\Campaigns\CampaignExporter;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// §2.3.9 — campaign actions: pause/resume, cancel/block, copy, delete, export.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

function wp12_actions_campaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'customer_id' => test()->customer->id,
        'email_list_id' => test()->list->id,
    ], $overrides));
}

// F-2.3.9-001 — pause/resume through the table.
it('pauses and resumes a sending campaign', function (): void {
    $campaign = wp12_actions_campaign(['status' => CampaignStatus::Sending, 'send_started_at' => now()]);

    Livewire::test(ListCampaigns::class)->callTableAction('pause', $campaign);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Paused);

    Livewire::test(ListCampaigns::class)->callTableAction('resume', $campaign->refresh());
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
});

// F-2.3.9-002 — cancel and block.
it('cancels and blocks campaigns per the status machine', function (): void {
    $scheduled = wp12_actions_campaign(['status' => CampaignStatus::Scheduled, 'send_at' => now()->addDay()]);
    Livewire::test(ListCampaigns::class)->callTableAction('cancel', $scheduled);
    expect($scheduled->refresh()->status)->toBe(CampaignStatus::Cancelled);

    $sending = wp12_actions_campaign(['status' => CampaignStatus::Sending]);
    Livewire::test(ListCampaigns::class)->callTableAction('block', $sending);
    expect($sending->refresh()->status)->toBe(CampaignStatus::Blocked);
});

// F-2.3.9-003 — copy with content + options.
it('copies a campaign fully: new uid, draft status, no stats', function (): void {
    Storage::fake('public');

    $source = wp12_actions_campaign([
        'status' => CampaignStatus::Sent,
        'content_html' => '<p>Body {UNSUBSCRIBE_URL}</p>',
        'notes' => 'Keep me',
        'share_reports' => true,
        'share_reports_secret' => 'old-secret',
        'delivery_total' => 100,
        'delivery_sent' => 90,
        'send_started_at' => now()->subDay(),
        'send_finished_at' => now(),
        'send_rate_per_hour' => 500,
    ]);
    $source->attachments()->create([
        'customer_id' => $this->customer->id,
        'file_name' => 'doc.pdf',
        'path' => $source->assetDirectory().'/attachments/doc.pdf',
        'mime_type' => 'application/pdf',
        'size' => 100,
    ]);
    Storage::disk('public')->put($source->assetDirectory().'/attachments/doc.pdf', 'pdf-bytes');

    $copy = app(CampaignCopier::class)->copy($source);

    expect($copy->uid)->not->toBe($source->uid)
        ->and($copy->status)->toBe(CampaignStatus::Draft)
        ->and($copy->content_html)->toContain('Body')
        ->and($copy->notes)->toBe('Keep me')
        ->and($copy->send_rate_per_hour)->toBe(500)
        // Run state never carries over.
        ->and($copy->share_reports_secret)->toBeNull()
        ->and((int) $copy->delivery_total)->toBe(0)
        ->and($copy->send_started_at)->toBeNull()
        // Attachments copied with rewritten paths.
        ->and($copy->attachments()->count())->toBe(1)
        ->and($copy->attachments()->first()->path)->toContain($copy->uid);
});

it('copies without content and without options on demand', function (): void {
    $source = wp12_actions_campaign([
        'content_html' => '<p>Body</p>',
        'plain_text' => 'Body',
        'notes' => 'Secret notes',
        'send_rate_per_hour' => 500,
        'utm_enabled' => true,
    ]);

    $bare = app(CampaignCopier::class)->copy($source, withContent: false, withOptions: false);

    expect($bare->content_html)->toBeNull()
        ->and($bare->plain_text)->toBeNull()
        ->and($bare->notes)->toBeNull()
        ->and($bare->send_rate_per_hour)->toBeNull()
        ->and($bare->utm_enabled)->toBeFalse()
        // Step-1 details always survive.
        ->and($bare->name)->toContain($source->name)
        ->and($bare->email_list_id)->toBe($source->email_list_id);
});

it('copies via the table action', function (): void {
    $source = wp12_actions_campaign(['name' => 'Original']);

    Livewire::test(ListCampaigns::class)
        ->callTableAction('copy', $source, data: ['with_content' => true, 'with_options' => false]);

    expect(Campaign::query()->where('name', 'like', 'Original %')->exists())->toBeTrue();
});

// Copy respects the max_campaigns quota.
it('refuses to copy beyond the campaign quota', function (): void {
    $this->customer->limitOverrides()->create(['key' => 'max_campaigns', 'value' => 1]);

    $source = wp12_actions_campaign();

    expect(fn () => app(CampaignCopier::class)->copy($source))
        ->toThrow(ValidationException::class);
});

// F-2.3.9-005 — delete is a soft delete.
it('soft deletes a campaign from the table', function (): void {
    $campaign = wp12_actions_campaign();

    Livewire::test(ListCampaigns::class)->callTableAction('delete', $campaign);

    $this->assertSoftDeleted('campaigns', ['id' => $campaign->id]);
});

// F-2.3.9-007 — export as HTML and self-contained ZIP.
it('exports campaign content as html and zip', function (): void {
    Storage::fake('public');

    $campaign = wp12_actions_campaign([
        'name' => 'Export me',
        'content_html' => '<html><body><p>Exportable {UNSUBSCRIBE_URL}</p></body></html>',
    ]);
    Storage::disk('public')->put($campaign->assetDirectory().'/img/logo.png', 'png-bytes');

    $exporter = app(CampaignExporter::class);

    expect($exporter->fileName($campaign, 'html'))->toBe('export-me.html')
        ->and($exporter->html($campaign))->toContain('Exportable');

    $zipPath = $exporter->zip($campaign);
    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->getFromName('index.html'))->toContain('Exportable')
        ->and($zip->getFromName('assets/img/logo.png'))->toBe('png-bytes');

    $zip->close();
    unlink($zipPath);
});
