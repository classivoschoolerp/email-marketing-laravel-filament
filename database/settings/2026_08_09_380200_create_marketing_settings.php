<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Marketing / public homepage settings (spec §1.1 F-1.1-001 … 003, WP-38).
 *
 * `public_site_enabled` defaults to TRUE: the platform this one models ships
 * with a public frontend and F-1.1-003 is written as a switch to turn it
 * OFF ("pure self-hosted mode"), so the default state is the one the toggle
 * exists to leave. An operator who wants no public surface flips it once.
 *
 * With every text field seeded NULL, the default homepage is a hero built
 * from the site name plus the live pricing table — no vendor copy anywhere,
 * which is what makes §4.7 F-4.7-003 true of the front page as well.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('marketing', function (SettingsBlueprint $blueprint): void {
            // F-1.1-003
            $blueprint->add('public_site_enabled', true);

            // F-1.1-002 hero
            $blueprint->add('hero_heading', null);
            $blueprint->add('hero_subheading', null);
            $blueprint->add('hero_cta_label', null);
            $blueprint->add('hero_cta_url', null);
            $blueprint->add('hero_image_path', null);

            // F-1.1-002 features / testimonials / FAQ (repeaters)
            $blueprint->add('features_heading', null);
            $blueprint->add('features', []);
            $blueprint->add('testimonials_heading', null);
            $blueprint->add('testimonials', []);
            $blueprint->add('faqs_heading', null);
            $blueprint->add('faqs', []);

            // F-1.1-002 pricing table — rendered from LIVE price plans, so
            // there is nothing to duplicate here beyond presentation.
            $blueprint->add('pricing_enabled', true);
            $blueprint->add('pricing_heading', null);
            $blueprint->add('pricing_currency', null);

            // Blog surface (§1.11) — separable from the marketing homepage.
            $blueprint->add('blog_enabled', true);
            $blueprint->add('blog_heading', null);
            $blueprint->add('blog_page_size', 12);

            // §1.11 F-1.11-003
            $blueprint->add('sitemap_enabled', true);
            $blueprint->add('robots_enabled', true);
            $blueprint->add('robots_extra', null);

            // SEO defaults for the public site.
            $blueprint->add('meta_title', null);
            $blueprint->add('meta_description', null);
        });
    }

    public function down(): void
    {
        $this->migrator->deleteGroup('marketing');
    }
};
