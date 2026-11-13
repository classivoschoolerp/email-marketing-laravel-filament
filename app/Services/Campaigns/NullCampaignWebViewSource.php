<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Contracts\CampaignWebViewSource;

/**
 * Default CampaignWebViewSource until WP-11 exists: every campaign uid and
 * report secret is unknown, so all public campaign pages 404. WP-11 replaces
 * this by binding its real implementation in the container.
 */
class NullCampaignWebViewSource implements CampaignWebViewSource
{
    public function webViewHtml(string $campaignUid): ?string
    {
        return null;
    }

    public function shareMeta(string $campaignUid): ?array
    {
        return null;
    }

    public function reportHtml(string $secret): ?string
    {
        return null;
    }
}
