<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Events\Campaign\CampaignLinkClicked;
use App\Events\Campaign\CampaignOpened;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\CampaignTrackUrl;
use App\Models\Subscriber;
use App\Services\Ecommerce\SiteTracking;
use App\Services\Geo\IpLocationResolver;
use App\Services\Settings\SettingsReader;
use App\Services\Tracking\UrlSigner;
use App\Services\Tracking\UserAgentParser;
use App\Support\Privacy\IpAnonymizer;
use App\Support\Stats\LiveCounter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Campaign tracking endpoints (spec §1.7). Design notes:
 *
 * - Rows are inserted DIRECTLY (no queue): a single indexed insert is ~1ms,
 *   while a queue round-trip would add latency before the redirect/pixel
 *   response and double the write volume (job row + log row) on the same
 *   database. Hot aggregates go through LiveCounter (cache-backed, drained
 *   to DB by the maintenance flush) so reporting reads never scan raw logs.
 * - campaign_id stays null until WP-11 exists; campaign_uid is recorded and
 *   WP-11 backfills/attaches ids. Click URLs are already tamper-proof via
 *   the UrlSigner HMAC lookup (F-1.7-008).
 * - Routes are host-agnostic, so custom tracking domains (F-1.7-005) work
 *   as soon as a domain points at the app; domain management is §2.9.8.
 * - Geo columns stay null until a GeoIP provider is configured (D-10);
 *   bot/prefetch filtering is P2 (F-1.7-007).
 *
 * WP-24 SURGICAL EDIT (§3.23): country/region/city are now filled at capture
 * time by App\Services\Geo\IpLocationResolver. The resolver has a null-provider
 * fast path (no cache read, no network, no settings-heavy work) so the default
 * "no GeoIP configured" install behaves exactly as before, and it never throws
 * — an unreachable provider yields nulls rather than losing the hit.
 */
class TrackingController extends Controller
{
    /** 1x1 transparent GIF, base64. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private readonly UserAgentParser $parser,
        private readonly LiveCounter $counter,
        private readonly IpLocationResolver $geo,
    ) {}

    /** GET /campaigns/{uid}/track-opening/{subscriber_uid} (F-1.7-001). */
    public function open(Request $request, string $campaignUid, string $subscriberUid): Response
    {
        $meta = $this->requestMeta($request);

        CampaignTrackOpen::create([
            'campaign_uid' => $campaignUid,
            'subscriber_uid' => $subscriberUid,
            'created_at' => now(),
            ...$meta,
        ]);

        Subscriber::withoutTenancy()
            ->where('uid', $subscriberUid)
            ->update(['last_open_at' => now()]);

        $this->counter->increment('track:opens');
        $this->counter->increment('campaigns:'.$campaignUid.':opens');

        // WP-36 (§6.1 F-6.1-012) — HOOK `campaign.on_open`. Dispatched after
        // the row and counters, so a listener never sees a half-recorded
        // open. The IP handed over is the already-anonymised one.
        CampaignOpened::dispatch(
            $campaignUid,
            $subscriberUid,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
        );

        return response(base64_decode(self::PIXEL, true) ?: '', 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * GET /campaigns/{uid}/track-url/{subscriber_uid}/{url_hash}
     * (F-1.7-002/008): the hash is the HMAC of the destination URL — an
     * unknown or tampered hash matches no row and 404s; the stored row is
     * re-verified against the HMAC before redirecting.
     */
    public function click(Request $request, string $campaignUid, string $subscriberUid, string $urlHash, UrlSigner $signer): RedirectResponse
    {
        /** @var CampaignTrackUrl|null $url */
        $url = CampaignTrackUrl::query()
            ->where('campaign_uid', $campaignUid)
            ->where('hash', $urlHash)
            ->first();

        abort_if($url === null, 404);
        abort_unless($signer->verify((string) $url->url, $urlHash), 404);

        $meta = $this->requestMeta($request);

        CampaignTrackClick::create([
            'campaign_uid' => $campaignUid,
            'subscriber_uid' => $subscriberUid,
            'url_id' => $url->id,
            'created_at' => now(),
            ...$meta,
        ]);

        Subscriber::withoutTenancy()
            ->where('uid', $subscriberUid)
            ->update(['last_click_at' => now()]);

        $this->counter->increment('track:clicks');
        $this->counter->increment('campaigns:'.$campaignUid.':clicks');

        // WP-36 (§6.1 F-6.1-012) — HOOK `campaign.on_click`. Fired after the
        // HMAC check, so the URL a listener receives is provably one this
        // installation generated (F-1.7-008).
        CampaignLinkClicked::dispatch(
            $campaignUid,
            $subscriberUid,
            (string) $url->url,
            (int) $url->id,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
        );

        return redirect()->away($this->withGoalParams($campaignUid, $subscriberUid, (string) $url->url), 302);
    }

    /**
     * WP-45 (§2.3.4 F-2.3.4-024): when the campaign opted into goal tracking,
     * the destination URL carries the attribution triple the merchant's
     * site-tracking script captures into a first-party cookie and replays on
     * the thank-you page.
     *
     * `emp_sig` is an HMAC over the campaign/subscriber pair, so a shopper who
     * edits the URL cannot post revenue for a campaign they were never sent —
     * the same reasoning that signs the click URL itself (F-1.7-008).
     *
     * COST CONTROL: gated behind a 60-second cached per-campaign flag, so an
     * install where nobody uses goal tracking — the default — pays one cache
     * read on the hottest endpoint in the product and nothing else.
     */
    private function withGoalParams(string $campaignUid, string $subscriberUid, string $url): string
    {
        $enabled = Cache::remember(
            'ecommerce:goal-tracking:'.$campaignUid,
            60,
            static fn (): bool => (bool) Campaign::withoutTenancy()
                ->where('uid', $campaignUid)
                ->value('goal_tracking_enabled'),
        );

        if (! $enabled || ! class_exists(SiteTracking::class)) {
            return $url;
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return $url; // never rewrite a mailto:/tel: destination
        }

        $params = [
            SiteTracking::PARAM_CAMPAIGN => $campaignUid,
            SiteTracking::PARAM_SUBSCRIBER => $subscriberUid,
            SiteTracking::PARAM_SIGNATURE => app(SiteTracking::class)
                ->conversionSignature($campaignUid, $subscriberUid),
        ];

        $separator = str_contains($url, '?') ? '&' : '?';
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);

        $rebuilt = $base.$separator.http_build_query($params);

        return $fragment === null ? $rebuilt : $rebuilt.'#'.$fragment;
    }

    /**
     * GET /campaigns/{uid}/track-unsubscribe/{subscriber_uid} (F-1.7-003):
     * records which campaign the unsubscribe came from, then hands off to
     * the §1.5 list unsubscribe flow.
     */
    public function unsubscribe(Request $request, string $campaignUid, string $subscriberUid): RedirectResponse
    {
        /** @var Subscriber|null $subscriber */
        $subscriber = Subscriber::withoutTenancy()->where('uid', $subscriberUid)->first();

        abort_if($subscriber === null, 404);

        CampaignTrackUnsubscribe::create([
            'campaign_uid' => $campaignUid,
            'subscriber_uid' => $subscriberUid,
            'created_at' => now(),
            ...$this->requestMeta($request),
        ]);

        $this->counter->increment('track:unsubscribes');
        $this->counter->increment('campaigns:'.$campaignUid.':unsubscribes');

        $list = $subscriber->list()->withoutGlobalScopes()->firstOrFail();

        return redirect()->route('lists.public.unsubscribe', [
            'list_uid' => $list->uid,
            'subscriber_uid' => $subscriber->uid,
        ]);
    }

    /** @return array<string, mixed> ip/user_agent + parsed UA + geo columns. */
    private function requestMeta(Request $request): array
    {
        $userAgent = $request->userAgent();
        $ip = (string) $request->ip();

        return [
            // WP-22 (§3.25.16 F-3.25.16-005 / §4.5 F-4.5-009): anonymised
            // analytics — the host portion is zeroed at capture time when the
            // privacy toggle is on, so no raw visitor IP ever reaches the
            // tracking tables. Geo resolution still runs on the full address
            // (it happens in-process and stores no IP).
            'ip' => IpAnonymizer::maybe($ip),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            ...$this->parser->parse($userAgent)->toArray(),
            ...$this->geoColumns($ip),
        ];
    }

    /**
     * WP-24 (§3.23): geo enrichment at capture time, guarded by the §3.23
     * enrichment toggle and the resolver's null-provider fast path.
     *
     * @return array{country?: ?string, region?: ?string, city?: ?string}
     */
    private function geoColumns(string $ip): array
    {
        if (! $this->geo->enabled()) {
            return [];
        }

        try {
            if (! SettingsReader::location()->enrichTracking()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        return $this->geo->locate($ip)?->trackingColumns() ?? [];
    }
}
