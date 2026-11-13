<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Campaigns\NullCampaignWebViewSource;
use Illuminate\Container\Attributes\Bind;

/**
 * Content source for the public campaign pages (spec §1.6). WP-09 ships the
 * routes/controller/views but the campaigns module (WP-11) owns campaign
 * storage — so the controller talks to this interface only.
 *
 * The #[Bind] attribute makes NullCampaignWebViewSource (404 for everything)
 * the container default; WP-11 swaps the implementation by registering an
 * explicit binding in its service provider, which takes precedence over the
 * attribute default.
 */
#[Bind(NullCampaignWebViewSource::class)]
interface CampaignWebViewSource
{
    /**
     * Full HTML of the campaign's web version ("View in browser",
     * F-1.6-001). Null = unknown/unavailable campaign → 404.
     */
    public function webViewHtml(string $campaignUid): ?string;

    /**
     * OG metadata for the social share page (F-1.6-003):
     * ['title' => string, 'description' => string|null, 'image' => string|null].
     * Null = unknown/unavailable campaign → 404.
     *
     * @return array{title: string, description: ?string, image: ?string}|null
     */
    public function shareMeta(string $campaignUid): ?array;

    /**
     * Read-only public report HTML for a secret share URL (F-1.6-004).
     * Null = unknown/disabled secret → 404.
     */
    public function reportHtml(string $secret): ?string;
}
