<?php

declare(strict_types=1);

use Tests\Support\CampaignReportFixture;

// §2.3.8 F-2.3.8-019 (+ §1.6 F-1.6-004) — the public share-secret report now
// renders the real CampaignReportService numbers + top links (WP-17 upgrade
// of DatabaseCampaignWebViewSource::reportHtml).

it('renders real stats and top links on the public shared report', function (): void {
    $fixture = CampaignReportFixture::build();

    $fixture->campaign->forceFill([
        'share_reports' => true,
        'share_reports_secret' => 'wp17-secret',
    ])->save();

    $response = $this->get(route('campaigns.public.report', ['secret' => 'wp17-secret']))
        ->assertOk()
        // Original WP-09/11 keys stay intact.
        ->assertSee(__('campaigns.public_report.unique_opens'))
        ->assertSee(__('campaigns.public_report.recipients'))
        // Real numbers from the fixture.
        ->assertSee('50%') // open rate + CTOR
        ->assertSee('25%') // click rate
        ->assertSee(__('reports.stats.bounced'))
        ->assertSee(__('reports.tabs.top_links'))
        ->assertSee('https://example.com/a');

    // Exact figures: recipients 5, delivered 4, unique opens 2, total 4.
    $html = $response->getContent();

    expect(substr_count($html, '<dd class="mt-1 text-2xl font-semibold text-gray-900">5</dd>'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($html, '<dd class="mt-1 text-2xl font-semibold text-gray-900">4</dd>'))->toBeGreaterThanOrEqual(1);

    // Wrong secret / sharing off still 404 (existing contract).
    $this->get(route('campaigns.public.report', ['secret' => 'nope']))->assertNotFound();
});
