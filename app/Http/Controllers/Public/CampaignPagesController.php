<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Contracts\CampaignWebViewSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Public campaign pages (spec §1.6): web view / "view in browser"
 * (F-1.6-001), the social share page with OG tags (F-1.6-003) and the
 * secret-URL public report share (F-1.6-004).
 *
 * WP-09 plumbing: content is resolved through CampaignWebViewSource, whose
 * container default (NullCampaignWebViewSource) knows no campaigns → 404.
 * WP-11 binds the real source; these routes/views then light up unchanged.
 */
class CampaignPagesController extends Controller
{
    public function __construct(private readonly CampaignWebViewSource $source) {}

    /** GET /campaigns/{uid}/web-view (F-1.6-001). */
    public function webView(string $campaignUid): Response
    {
        $html = $this->source->webViewHtml($campaignUid);

        abort_if($html === null, 404);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** GET /campaigns/{uid}/share — OG-tagged share page (F-1.6-003). */
    public function share(string $campaignUid): Response
    {
        $meta = $this->source->shareMeta($campaignUid);

        abort_if($meta === null, 404);

        return response()->view('public.campaigns.share', [
            'meta' => $meta,
            'webViewUrl' => route('campaigns.public.web_view', ['campaign_uid' => $campaignUid]),
        ]);
    }

    /** GET /campaigns/reports/{secret} — read-only shared report (F-1.6-004). */
    public function report(string $secret): Response
    {
        $html = $this->source->reportHtml($secret);

        abort_if($html === null, 404);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
