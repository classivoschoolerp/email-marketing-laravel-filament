<?php

declare(strict_types=1);

use App\Contracts\CampaignWebViewSource;
use App\Services\Campaigns\NullCampaignWebViewSource;

// §1.6 plumbing: the container default is the Null source → every campaign
// page 404s until WP-11 binds the real implementation.
it('defaults to the null source and 404s all campaign public pages', function (): void {
    expect(app(CampaignWebViewSource::class))->toBeInstanceOf(NullCampaignWebViewSource::class);

    $this->get(route('campaigns.public.web_view', ['campaign_uid' => 'any-uid']))->assertNotFound();
    $this->get(route('campaigns.public.share', ['campaign_uid' => 'any-uid']))->assertNotFound();
    $this->get(route('campaigns.public.report', ['secret' => 'any-secret']))->assertNotFound();
});

// F-1.6-001/003/004: with a bound source (as WP-11 will provide) the pages
// serve content — proving the plumbing end-to-end.
it('serves web view, OG-tagged share page and report once a source is bound', function (): void {
    $this->app->bind(CampaignWebViewSource::class, fn (): CampaignWebViewSource => new class implements CampaignWebViewSource
    {
        public function webViewHtml(string $campaignUid): ?string
        {
            return $campaignUid === 'known-uid'
                ? '<html><body><h1>Campaign 内容 🚀</h1></body></html>'
                : null;
        }

        public function shareMeta(string $campaignUid): ?array
        {
            return $campaignUid === 'known-uid'
                ? ['title' => 'Spring Sale 🌸', 'description' => 'Big savings inside', 'image' => 'https://cdn.example/og.png']
                : null;
        }

        public function reportHtml(string $secret): ?string
        {
            return $secret === 'secret-token' ? '<html><body>Opens: 42</body></html>' : null;
        }
    });

    // Web view (F-1.6-001).
    $this->get(route('campaigns.public.web_view', ['campaign_uid' => 'known-uid']))
        ->assertOk()
        ->assertSee('Campaign 内容 🚀');
    $this->get(route('campaigns.public.web_view', ['campaign_uid' => 'other']))->assertNotFound();

    // Share page with OG tags (F-1.6-003).
    $this->get(route('campaigns.public.share', ['campaign_uid' => 'known-uid']))
        ->assertOk()
        ->assertSee('property="og:title" content="Spring Sale 🌸"', false)
        ->assertSee('property="og:description" content="Big savings inside"', false)
        ->assertSee('property="og:image" content="https://cdn.example/og.png"', false)
        ->assertSee(route('campaigns.public.web_view', ['campaign_uid' => 'known-uid']));

    // Secret-URL report share (F-1.6-004).
    $this->get(route('campaigns.public.report', ['secret' => 'secret-token']))
        ->assertOk()
        ->assertSee('Opens: 42');
    $this->get(route('campaigns.public.report', ['secret' => 'wrong']))->assertNotFound();
});
