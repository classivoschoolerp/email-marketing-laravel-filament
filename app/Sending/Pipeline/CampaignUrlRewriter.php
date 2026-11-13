<?php

declare(strict_types=1);

namespace App\Sending\Pipeline;

use App\Models\Campaign;
use App\Models\CampaignTrackUrl;
use App\Models\Subscriber;
use App\Services\Tracking\TrackingUrlBuilder;
use App\Services\Tracking\UrlSigner;
use Illuminate\Support\Str;

/**
 * Per-recipient content instrumentation (spec §1.7 / §2.3.4, WP-13):
 *
 *  - click tracking: every external http(s) href is swapped for the signed
 *    /campaigns/{uid}/track-url/{subscriber}/{hash} redirect (F-1.7-002),
 *    creating the campaign_track_urls row (campaign_id backfilled at insert)
 *    unless the URL matches a tracking exception (F-2.3.4-008);
 *  - UTM parameters are appended to the DESTINATION url first, so analytics
 *    see them after the redirect (F-2.3.4-010);
 *  - open tracking: the 1×1 pixel is injected before </body> (F-1.7-001).
 *
 * Links pointing back at this application (unsubscribe / update-profile /
 * web-version merge-tag URLs) are functional, not editorial — they are never
 * rewritten through the click tracker.
 */
class CampaignUrlRewriter
{
    public function __construct(
        private readonly UrlSigner $signer,
        private readonly TrackingUrlBuilder $trackingUrls,
    ) {}

    /**
     * @param  array<string, string>  $utm  resolved utm param map (source/medium/campaign/term/content)
     * @param  bool  $injectPixel  false for plain-text-only sends (no html part to open)
     */
    public function process(Campaign $campaign, Subscriber $subscriber, string $html, array $utm = [], bool $injectPixel = true): string
    {
        $exceptions = array_map('strval', (array) ($campaign->url_tracking_exceptions ?? []));

        $html = (string) preg_replace_callback(
            '/(href\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/iu',
            function (array $match) use ($campaign, $subscriber, $utm, $exceptions): string {
                $url = $match[3];

                if ($this->isAppUrl($url)) {
                    return $match[0];
                }

                if ($utm !== []) {
                    $url = $this->appendUtm($url, $utm);
                }

                if ($campaign->track_clicks && ! $this->isException($url, $exceptions)) {
                    $url = $this->trackedUrl($campaign, $subscriber, $url);
                }

                return $match[1].$match[2].$url.$match[2];
            },
            $html,
        );

        if ($injectPixel && $campaign->track_opens) {
            $html = $this->injectPixel($campaign, $subscriber, $html);
        }

        return $html;
    }

    /** Append utm_* query parameters (existing query preserved). */
    public function appendUtm(string $url, array $utm): string
    {
        $params = [];

        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $value = trim((string) ($utm[$key] ?? ''));

            if ($value !== '') {
                $params['utm_'.$key] = $value;
            }
        }

        if ($params === []) {
            return $url;
        }

        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.http_build_query($params, '', '&', PHP_QUERY_RFC3986)
            .($fragment !== null ? '#'.$fragment : '');
    }

    /** Signed redirect through the WP-09 click endpoint + track-url row. */
    private function trackedUrl(Campaign $campaign, Subscriber $subscriber, string $url): string
    {
        $hash = $this->signer->hash($url);

        CampaignTrackUrl::query()->firstOrCreate(
            ['campaign_uid' => $campaign->uid, 'hash' => $hash],
            ['url' => $url, 'campaign_id' => $campaign->id],
        );

        // F-1.7-005: serve through the campaign's verified tracking domain when
        // one is configured, else app.url (TrackingUrlBuilder decides).
        return $this->trackingUrls->rebaseOnto(
            route('campaigns.track.click', [
                'campaign_uid' => $campaign->uid,
                'subscriber_uid' => $subscriber->uid,
                'url_hash' => $hash,
            ]),
            $this->trackingUrls->forCampaign($campaign),
        );
    }

    private function injectPixel(Campaign $campaign, Subscriber $subscriber, string $html): string
    {
        $openUrl = $this->trackingUrls->rebaseOnto(
            route('campaigns.track.open', [
                'campaign_uid' => $campaign->uid,
                'subscriber_uid' => $subscriber->uid,
            ]),
            $this->trackingUrls->forCampaign($campaign),
        );

        $pixel = '<img src="'.e($openUrl).'" width="1" height="1" alt="" style="border:0;width:1px;height:1px;" />';

        $position = stripos($html, '</body>');

        if ($position !== false) {
            return substr($html, 0, $position).$pixel.substr($html, $position);
        }

        return $html.$pixel;
    }

    /** Tracking exception match: exact URL or Str::is wildcard pattern. */
    private function isException(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && ($pattern === $url || Str::is($pattern, $url))) {
                return true;
            }
        }

        return false;
    }

    /** Own-app URLs (merge-tag unsubscribe/profile/web-version links) stay functional. */
    private function isAppUrl(string $url): bool
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);

        return $appHost !== null && $host !== null && strcasecmp((string) $appHost, (string) $host) === 0;
    }
}
