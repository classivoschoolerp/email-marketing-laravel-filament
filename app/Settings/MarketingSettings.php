<?php

declare(strict_types=1);

namespace App\Settings;

use App\Services\Settings\Readers\MarketingSettingsReader;
use Spatie\LaravelSettings\Settings;

/**
 * Public marketing site settings (spec §1.1 + §1.11, WP-38).
 *
 * Read through {@see MarketingSettingsReader}.
 *
 * The pricing table is NOT stored here: it is rendered from the live
 * §3.26.1 `price_plans` rows (F-1.1-002), so a price change on a plan is a
 * price change on the homepage with nothing to re-enter.
 */
class MarketingSettings extends Settings
{
    /** Master switch for the whole public site (F-1.1-003). */
    public bool $public_site_enabled;

    // Hero (F-1.1-002)
    public ?string $hero_heading;

    public ?string $hero_subheading;

    public ?string $hero_cta_label;

    public ?string $hero_cta_url;

    public ?string $hero_image_path;

    // Features (F-1.1-002)
    public ?string $features_heading;

    /*
     | Repeater rows below are deliberately annotated in PROSE, not with a
     | `@var` tag. spatie/laravel-settings reflects `@var` to build a cast and
     | supports only primitives, objects and lists OF those — an array-shape
     | (`list<array{title: string}>`) crashes its phpdoc parser at boot, and a
     | nested generic (`array<int, array<string, mixed>>`) throws
     | CouldNotResolveDocblockType. With no tag it uses the native `array`
     | type, needs no cast, and the value round-trips as JSON. The row shapes
     | are enforced by MarketingSettingsReader, which is what the views read.
     */

    /** Rows of: title, description, icon. */
    public array $features;

    // Testimonials (F-1.1-002)
    public ?string $testimonials_heading;

    /** Rows of: name, role, quote, avatar_path. */
    public array $testimonials;

    // FAQ (F-1.1-002)
    public ?string $faqs_heading;

    /** Rows of: question, answer. */
    public array $faqs;

    // Pricing (F-1.1-002) — presentation only; the plans themselves are live.
    public bool $pricing_enabled;

    public ?string $pricing_heading;

    /** ISO code the table prices in; null = the first plan's own currency. */
    public ?string $pricing_currency;

    // Blog (§1.11)
    public bool $blog_enabled;

    public ?string $blog_heading;

    public int $blog_page_size;

    // §1.11 F-1.11-003
    public bool $sitemap_enabled;

    public bool $robots_enabled;

    public ?string $robots_extra;

    // Site-wide SEO defaults
    public ?string $meta_title;

    public ?string $meta_description;

    public static function group(): string
    {
        return 'marketing';
    }

    /**
     * The seeded values, as a plain array.
     *
     * Used ONLY by the installer-safety path in
     * {@see \App\Http\Controllers\Public\MarketingController::home()}: spatie
     * settings hydrate lazily from the database, and `/` must still render
     * before `migrate` has run. `new MarketingSettings(self::defaults())`
     * loads without touching a table.
     *
     * Kept in step with database/settings/2026_08_09_380200_create_marketing_settings.php.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'public_site_enabled' => true,
            'hero_heading' => null,
            'hero_subheading' => null,
            'hero_cta_label' => null,
            'hero_cta_url' => null,
            'hero_image_path' => null,
            'features_heading' => null,
            'features' => [],
            'testimonials_heading' => null,
            'testimonials' => [],
            'faqs_heading' => null,
            'faqs' => [],
            'pricing_enabled' => true,
            'pricing_heading' => null,
            'pricing_currency' => null,
            'blog_enabled' => true,
            'blog_heading' => null,
            'blog_page_size' => 12,
            'sitemap_enabled' => true,
            'robots_enabled' => true,
            'robots_extra' => null,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }
}
