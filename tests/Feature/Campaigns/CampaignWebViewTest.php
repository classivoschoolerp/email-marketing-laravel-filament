<?php

declare(strict_types=1);

use App\Contracts\CampaignWebViewSource;
use App\Models\Campaign;
use App\Models\CampaignTrackOpen;
use App\Services\Campaigns\DatabaseCampaignWebViewSource;
use App\Services\Campaigns\NullCampaignWebViewSource;

// §1.6 + §2.3.3/§2.3.4 — the campaigns module binds the real web-view
// source; WP-09's routes/views light up unchanged.

it('binds the database source as the container default', function (): void {
    $source = app(CampaignWebViewSource::class);

    expect($source)->toBeInstanceOf(DatabaseCampaignWebViewSource::class)
        // WP-09's plumbing test asserts the Null default — the subclass
        // relationship keeps that assertion true.
        ->and($source)->toBeInstanceOf(NullCampaignWebViewSource::class);
});

it('serves the campaign web view with merge tags resolved and spintax rendered', function (): void {
    $campaign = Campaign::factory()->create([
        'name' => 'Summer blast',
        'subject' => 'Big news',
        'content_html' => '<html><body><h1>{Hello|Hi} reader</h1>'
            .'<p>{CAMPAIGN_NAME} 内容 🚀</p><a href="{UNSUBSCRIBE_URL}">bye</a></body></html>',
    ]);

    $response = $this->get(route('campaigns.public.web_view', ['campaign_uid' => $campaign->uid]))
        ->assertOk()
        ->assertSee('Summer blast')
        ->assertSee('内容 🚀');

    // Spintax rendered to a single deterministic permutation.
    $html = $response->getContent();
    expect($html)->not->toContain('{Hello|Hi}')
        ->and(str_contains($html, 'Hello reader') || str_contains($html, 'Hi reader'))->toBeTrue()
        // Personal links are neutralized on the public page.
        ->and($html)->not->toContain('{UNSUBSCRIBE_URL}');
});

it('404s for unknown campaigns and campaigns without content', function (): void {
    $this->get(route('campaigns.public.web_view', ['campaign_uid' => 'missing']))->assertNotFound();

    $empty = Campaign::factory()->create(['content_html' => null]);
    $this->get(route('campaigns.public.web_view', ['campaign_uid' => $empty->uid]))->assertNotFound();
});

it('serves the OG share page from the campaign subject and preheader', function (): void {
    $campaign = Campaign::factory()->create([
        'subject' => 'Spring Sale 🌸',
        'preheader' => 'Big savings inside',
    ]);

    $this->get(route('campaigns.public.share', ['campaign_uid' => $campaign->uid]))
        ->assertOk()
        ->assertSee('property="og:title" content="Spring Sale 🌸"', false)
        ->assertSee('property="og:description" content="Big savings inside"', false);
});

it('serves the public report only with the right secret and sharing enabled', function (): void {
    $campaign = Campaign::factory()->create([
        'share_reports' => true,
        'share_reports_secret' => 'secret-abc',
    ]);

    // Tracking rows key on the campaign uid (WP-09 tables).
    CampaignTrackOpen::create([
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => 'sub-1',
    ]);

    $this->get(route('campaigns.public.report', ['secret' => 'secret-abc']))
        ->assertOk()
        ->assertSee($campaign->name)
        ->assertSee(__('campaigns.public_report.unique_opens'));

    $this->get(route('campaigns.public.report', ['secret' => 'wrong']))->assertNotFound();

    $campaign->forceFill(['share_reports' => false])->save();
    $this->get(route('campaigns.public.report', ['secret' => 'secret-abc']))->assertNotFound();
});
