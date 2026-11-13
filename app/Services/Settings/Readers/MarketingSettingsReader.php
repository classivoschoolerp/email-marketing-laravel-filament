<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\MarketingSettings;

/**
 * Typed accessors for the `marketing` settings group (spec §1.1, §1.11).
 *
 * The repeater groups (features/testimonials/FAQ) are normalised here rather
 * than in the views: a Filament repeater stores whatever the operator typed,
 * including rows where every field was left blank, and a public page must
 * never render an empty card.
 */
final readonly class MarketingSettingsReader
{
    public function __construct(private MarketingSettings $settings) {}

    /** F-1.1-003 — master switch for the public marketing site. */
    public function publicSiteEnabled(): bool
    {
        return $this->settings->public_site_enabled;
    }

    public function heroHeading(): ?string
    {
        return $this->blank($this->settings->hero_heading);
    }

    public function heroSubheading(): ?string
    {
        return $this->blank($this->settings->hero_subheading);
    }

    public function heroCtaLabel(): ?string
    {
        return $this->blank($this->settings->hero_cta_label);
    }

    public function heroCtaUrl(): ?string
    {
        return $this->blank($this->settings->hero_cta_url);
    }

    public function heroImagePath(): ?string
    {
        return $this->blank($this->settings->hero_image_path);
    }

    public function featuresHeading(): ?string
    {
        return $this->blank($this->settings->features_heading);
    }

    /**
     * @return list<array{title: string, description: string, icon: ?string}>
     */
    public function features(): array
    {
        $out = [];

        foreach ($this->settings->features as $row) {
            $title = trim((string) ($row['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $out[] = [
                'title' => $title,
                'description' => trim((string) ($row['description'] ?? '')),
                'icon' => $this->blank($row['icon'] ?? null),
            ];
        }

        return $out;
    }

    public function testimonialsHeading(): ?string
    {
        return $this->blank($this->settings->testimonials_heading);
    }

    /**
     * @return list<array{name: string, role: string, quote: string, avatar_path: ?string}>
     */
    public function testimonials(): array
    {
        $out = [];

        foreach ($this->settings->testimonials as $row) {
            $quote = trim((string) ($row['quote'] ?? ''));

            if ($quote === '') {
                continue;
            }

            $out[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'role' => trim((string) ($row['role'] ?? '')),
                'quote' => $quote,
                'avatar_path' => $this->blank($row['avatar_path'] ?? null),
            ];
        }

        return $out;
    }

    public function faqsHeading(): ?string
    {
        return $this->blank($this->settings->faqs_heading);
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function faqs(): array
    {
        $out = [];

        foreach ($this->settings->faqs as $row) {
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            $out[] = ['question' => $question, 'answer' => $answer];
        }

        return $out;
    }

    public function pricingEnabled(): bool
    {
        return $this->settings->pricing_enabled;
    }

    public function pricingHeading(): ?string
    {
        return $this->blank($this->settings->pricing_heading);
    }

    public function pricingCurrency(): ?string
    {
        $code = $this->blank($this->settings->pricing_currency);

        return $code === null ? null : mb_strtoupper($code);
    }

    public function blogEnabled(): bool
    {
        return $this->settings->blog_enabled;
    }

    public function blogHeading(): ?string
    {
        return $this->blank($this->settings->blog_heading);
    }

    /** Clamped: a 0 or negative page size would paginate forever. */
    public function blogPageSize(): int
    {
        return max(1, min(100, $this->settings->blog_page_size));
    }

    public function sitemapEnabled(): bool
    {
        return $this->settings->sitemap_enabled;
    }

    public function robotsEnabled(): bool
    {
        return $this->settings->robots_enabled;
    }

    public function robotsExtra(): ?string
    {
        return $this->blank($this->settings->robots_extra);
    }

    public function metaTitle(): ?string
    {
        return $this->blank($this->settings->meta_title);
    }

    public function metaDescription(): ?string
    {
        return $this->blank($this->settings->meta_description);
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
