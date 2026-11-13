<?php

declare(strict_types=1);

namespace App\Settings;

use App\Providers\SkinServiceProvider;
use App\Services\Settings\Readers\SkinSettingsReader;
use Spatie\LaravelSettings\Settings;

/**
 * Customisation / skins settings (spec §3.25.8).
 *
 * The admin + customer panel CSS/JS injection is live now via
 * {@see SkinServiceProvider} render hooks; the frontend area
 * values, header/footer HTML and skin slugs are stored here and rendered by
 * the themes work package through
 * {@see SkinSettingsReader}.
 */
class SkinSettings extends Settings
{
    public const AREA_BACKEND = 'backend';

    public const AREA_CUSTOMER = 'customer';

    public const AREA_FRONTEND = 'frontend';

    /** @var list<string> */
    public const AREAS = [
        self::AREA_BACKEND,
        self::AREA_CUSTOMER,
        self::AREA_FRONTEND,
    ];

    /**
     * Available skin slugs. 'default' ships now; the others are placeholder
     * slugs the themes work package fills in.
     *
     * @var list<string>
     */
    public const SKINS = ['default', 'classic', 'modern'];

    // Skin selection per area (F-3.25.8-001)
    public string $skin_backend;

    public string $skin_customer;

    public string $skin_frontend;

    // Custom CSS per area (F-3.25.8-002)
    public ?string $custom_css_backend;

    public ?string $custom_css_customer;

    public ?string $custom_css_frontend;

    // Custom JS per area (F-3.25.8-002)
    public ?string $custom_js_backend;

    public ?string $custom_js_customer;

    public ?string $custom_js_frontend;

    // Custom header/footer HTML for the public frontend (F-3.25.8-003)
    public ?string $custom_header_html;

    public ?string $custom_footer_html;

    // White-label (F-3.25.8-004)
    public bool $hide_branding;

    /*
    |--------------------------------------------------------------------------
    | WP-38 additions (spec §6.3 F-6.3-003, §3.21 F-3.21-003, §4.7 F-4.7-001/003)
    |--------------------------------------------------------------------------
    | The themes work package extends this group instead of opening a second
    | branding group. Logo and favicon deliberately stay on CommonSettings
    | (F-3.25.1-002) so an installation has exactly one of each.
    */

    /** Hex brand colours layered on top of the active theme's palette. */
    public ?string $brand_primary_color;

    public ?string $brand_secondary_color;

    /** Login-screen background image — path on the `public` disk. */
    public ?string $login_background_path;

    /** Replaces the vendor product name everywhere (F-4.7-003). */
    public ?string $white_label_product_name;

    public static function group(): string
    {
        return 'skin';
    }
}
