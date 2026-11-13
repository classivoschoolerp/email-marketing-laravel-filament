<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Social links (spec §3.25.13) — used in public footers and email templates
 * via {@see \App\Services\Settings\Readers\SocialSettingsReader}.
 */
class SocialSettings extends Settings
{
    public ?string $facebook_url;

    /** X / Twitter. */
    public ?string $twitter_url;

    public ?string $instagram_url;

    public ?string $linkedin_url;

    public ?string $youtube_url;

    public ?string $tiktok_url;

    public static function group(): string
    {
        return 'social';
    }
}
