<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Providers\SkinServiceProvider;
use App\Settings\SkinSettings;
use InvalidArgumentException;

/**
 * Typed accessors for the `skin` settings group (spec §3.25.8).
 *
 * Areas: backend (admin panel), customer (customer panel), frontend (public
 * site). The panel CSS/JS is injected live by
 * {@see SkinServiceProvider}; the themes work package renders
 * the frontend values.
 */
final readonly class SkinSettingsReader
{
    public function __construct(private SkinSettings $settings) {}

    public function skin(string $area): string
    {
        return (string) $this->get('skin', $area);
    }

    public function customCss(string $area): ?string
    {
        return $this->get('custom_css', $area);
    }

    public function customJs(string $area): ?string
    {
        return $this->get('custom_js', $area);
    }

    public function headerHtml(): ?string
    {
        return $this->settings->custom_header_html;
    }

    public function footerHtml(): ?string
    {
        return $this->settings->custom_footer_html;
    }

    public function hideBranding(): bool
    {
        return $this->settings->hide_branding;
    }

    /*
    |--------------------------------------------------------------------------
    | WP-38 (§6.3 F-6.3-003, §3.21 F-3.21-003, §4.7 F-4.7-001/003)
    |--------------------------------------------------------------------------
    */

    /** Hex primary brand colour, or null when it is not a literal hex value. */
    public function brandPrimaryColor(): ?string
    {
        return $this->hex($this->settings->brand_primary_color);
    }

    public function brandSecondaryColor(): ?string
    {
        return $this->hex($this->settings->brand_secondary_color);
    }

    public function loginBackgroundPath(): ?string
    {
        return $this->blank($this->settings->login_background_path);
    }

    /**
     * The name the UI should use for the product. Falls back to the site name
     * and then to the configured app name, so this never returns an empty
     * string — F-4.7-003 is "remove vendor branding", which means having
     * something to put in its place.
     */
    public function productName(): string
    {
        $custom = $this->blank($this->settings->white_label_product_name);

        if ($custom !== null) {
            return $custom;
        }

        return (string) config('app.name');
    }

    /** True when the operator has replaced the vendor product name. */
    public function hasCustomProductName(): bool
    {
        return $this->blank($this->settings->white_label_product_name) !== null;
    }

    /**
     * Only literal hex colours survive: the value is interpolated into a
     * <style> tag, so anything else would be an injection point (same rule
     * as PlanBranding::safeColour, D-82).
     */
    private function hex(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : null;
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function get(string $prefix, string $area): ?string
    {
        if (! in_array($area, SkinSettings::AREAS, true)) {
            throw new InvalidArgumentException("Unknown skin area [{$area}].");
        }

        return $this->settings->{$prefix.'_'.$area};
    }
}
