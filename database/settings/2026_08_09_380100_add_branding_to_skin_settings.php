<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * WP-38 (§6.3 F-6.3-003, §3.21 F-3.21-003, §4.7 F-4.7-001/003) extends the
 * WP-05 `skin` group rather than opening a second, competing branding group.
 *
 * Deliberately NOT re-adding logo/favicon: those already exist as
 * CommonSettings::$logo_path / $favicon_path (F-3.25.1-002) and the live
 * customiser writes to them, so an installation has exactly one logo setting
 * instead of two that can disagree.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('skin', function (SettingsBlueprint $blueprint): void {
            // F-6.3-003 / F-3.21-003 — brand colours fed into every area as
            // CSS custom properties on top of the active theme's palette.
            $blueprint->add('brand_primary_color', null);
            $blueprint->add('brand_secondary_color', null);

            // F-3.21-003 — login background image (path on the public disk).
            $blueprint->add('login_background_path', null);

            // F-4.7-003 — replaces the product name everywhere the UI would
            // otherwise say it. Empty = use the vendor product name.
            $blueprint->add('white_label_product_name', null);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('skin', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('brand_primary_color');
            $blueprint->delete('brand_secondary_color');
            $blueprint->delete('login_background_path');
            $blueprint->delete('white_label_product_name');
        });
    }
};
