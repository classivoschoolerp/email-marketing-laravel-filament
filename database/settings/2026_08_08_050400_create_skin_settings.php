<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('skin', function (SettingsBlueprint $blueprint): void {
            // F-3.25.8-001
            $blueprint->add('skin_backend', 'default');
            $blueprint->add('skin_customer', 'default');
            $blueprint->add('skin_frontend', 'default');

            // F-3.25.8-002
            $blueprint->add('custom_css_backend', null);
            $blueprint->add('custom_css_customer', null);
            $blueprint->add('custom_css_frontend', null);
            $blueprint->add('custom_js_backend', null);
            $blueprint->add('custom_js_customer', null);
            $blueprint->add('custom_js_frontend', null);

            // F-3.25.8-003
            $blueprint->add('custom_header_html', null);
            $blueprint->add('custom_footer_html', null);

            // F-3.25.8-004
            $blueprint->add('hide_branding', false);
        });
    }
};
