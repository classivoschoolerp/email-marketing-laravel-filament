<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\SocialSettings;

/**
 * Typed accessors for the `social` settings group (spec §3.25.13) — used in
 * public footers and email templates.
 */
final readonly class SocialSettingsReader
{
    public function __construct(private SocialSettings $settings) {}

    public function facebookUrl(): ?string
    {
        return $this->settings->facebook_url;
    }

    public function twitterUrl(): ?string
    {
        return $this->settings->twitter_url;
    }

    public function instagramUrl(): ?string
    {
        return $this->settings->instagram_url;
    }

    public function linkedinUrl(): ?string
    {
        return $this->settings->linkedin_url;
    }

    public function youtubeUrl(): ?string
    {
        return $this->settings->youtube_url;
    }

    public function tiktokUrl(): ?string
    {
        return $this->settings->tiktok_url;
    }

    /**
     * Configured links only, keyed by network — ready for footer rendering.
     *
     * @return array<string, string>
     */
    public function links(): array
    {
        return array_filter([
            'facebook' => $this->settings->facebook_url,
            'twitter' => $this->settings->twitter_url,
            'instagram' => $this->settings->instagram_url,
            'linkedin' => $this->settings->linkedin_url,
            'youtube' => $this->settings->youtube_url,
            'tiktok' => $this->settings->tiktok_url,
        ], fn (?string $url): bool => $url !== null && $url !== '');
    }
}
