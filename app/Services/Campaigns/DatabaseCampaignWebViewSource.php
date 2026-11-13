<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Services\Content\MergeTags;
use App\Services\Content\Spintax;
use App\Services\Templates\TemplateThumbnails;

/**
 * Real CampaignWebViewSource for the WP-09 public campaign pages (spec §1.6)
 * now that campaigns exist.
 *
 * Extends NullCampaignWebViewSource deliberately: WP-09's plumbing test
 * asserts the container default is an instance of the Null source AND that
 * unknown uids/secrets 404 — both stay true with this subclass bound
 * (unknown rows return null from every method). Registered as the explicit
 * container binding in routes/modules/campaigns.php (D-41 pattern: the
 * providers list is frozen while agents run in parallel).
 *
 * Rendering: spintax resolved with a fixed per-campaign seed, merge tags
 * substituted with campaign values + neutral sample data (no subscriber
 * context on the public page — WP-13's per-subscriber renderer will pass
 * real values for the in-email {WEB_VERSION_URL} link).
 */
class DatabaseCampaignWebViewSource extends NullCampaignWebViewSource
{
    public function __construct(
        private readonly MergeTags $mergeTags,
        private readonly Spintax $spintax,
        private readonly TemplateThumbnails $thumbnails,
    ) {}

    public function webViewHtml(string $campaignUid): ?string
    {
        $campaign = $this->find($campaignUid);

        if ($campaign === null || $campaign->content_html === null || trim($campaign->content_html) === '') {
            return null;
        }

        // Merge tags render first (modifier/default forms are consumed as
        // tags, never as spintax — WP-13 MergeTags), then the fixed-seed
        // spintax pass resolves the remaining groups.
        return $this->spintax->render(
            $this->mergeTags->render($campaign->content_html, $this->tagData($campaign)),
            'web-view:'.$campaign->uid,
        );
    }

    public function shareMeta(string $campaignUid): ?array
    {
        $campaign = $this->find($campaignUid);

        if ($campaign === null) {
            return null;
        }

        return [
            'title' => (string) ($campaign->subject ?: $campaign->name),
            'description' => $campaign->preheader,
            'image' => $this->thumbnails->firstImageSource($campaign->content_html),
        ];
    }

    public function reportHtml(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        $campaign = Campaign::withoutTenancy()
            ->where('share_reports', true)
            ->where('share_reports_secret', $secret)
            ->first();

        if ($campaign === null) {
            return null;
        }

        $reports = app(\App\Services\Reports\CampaignReportService::class);

        return view('public.campaigns.report', [
            'campaign' => $campaign,
            'stats' => $this->stats($campaign),
            'header' => $reports->headerStats($campaign),
            'topLinks' => $reports->topLinks($campaign, 5),
        ])->render();
    }

    private function find(string $uid): ?Campaign
    {
        if ($uid === '') {
            return null;
        }

        return Campaign::withoutTenancy()->where('uid', $uid)->first();
    }

    /**
     * Publicly-safe stats (WP-17): the same CampaignReportService numbers the
     * customer report shows (single source, D-64), mapped onto the original
     * WP-09/11 key set so the existing public template contract holds.
     *
     * @return array<string, int>
     */
    private function stats(Campaign $campaign): array
    {
        $header = app(\App\Services\Reports\CampaignReportService::class)->headerStats($campaign);

        return [
            'recipients' => $header['recipients'],
            'delivered' => $header['delivered'],
            'opens' => $header['opens'],
            'unique_opens' => $header['unique_opens'],
            'clicks' => $header['clicks'],
            'unique_clicks' => $header['unique_clicks'],
            'unsubscribes' => $header['unsubscribes'],
        ];
    }

    /** @return array<string, string> */
    private function tagData(Campaign $campaign): array
    {
        $list = $campaign->list()->withoutGlobalScopes()->first();

        return array_merge($this->mergeTags->sampleValues($campaign, $list), [
            // No subscriber context on a public page — neutralize personal links.
            'UNSUBSCRIBE_URL' => '#',
            'DIRECT_UNSUBSCRIBE_URL' => '#',
            'UPDATE_PROFILE_URL' => '#',
            'FORWARD_URL' => '#',
        ]);
    }
}
